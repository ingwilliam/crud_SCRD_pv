<?php

//error_reporting(E_ALL);
//ini_set('display_errors', '1');
use Phalcon\Loader;
use Phalcon\Mvc\Micro;
use Phalcon\Di\FactoryDefault;
use Phalcon\Db\Adapter\Pdo\Postgresql as DbAdapter;
use Phalcon\Config\Adapter\Ini as ConfigIni;
use Phalcon\Http\Request;
use Phalcon\Logger\Adapter\File as FileAdapter;
use Phalcon\Logger\Formatter\Line;

// Definimos algunas rutas constantes para localizar recursos
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH);

//Defino las variables principales de conexion
$config = new ConfigIni('../config/config.ini');

// Registramos un autoloader
$loader = new Loader();

$loader->registerDirs(
        [
            APP_PATH . '/models/',
            APP_PATH . '/library/class/',
        ]
);

$loader->register();

// Crear un DI
$di = new FactoryDefault();

//Set up the database service
$di->set('db', function () use ($config) {
    return new DbAdapter(
            array(
        "host" => $config->database->host,
        "username" => $config->database->username,
        "password" => $config->database->password,
        "dbname" => $config->database->name
            )
    );
});

//Funcionalidad para crear los log de la aplicación
//la carpeta debe tener la propietario y usuario
//sudo chown -R www-data:www-data log/
//https://docs.phalcon.io/3.4/es-es/logging
$formatter = new Line('{"date":"%date%","type":"%type%",%message%},');
$formatter->setDateFormat('Y-m-d H:i:s');
$logger = new FileAdapter($config->sistema->path_log . "convocatorias." . date("Y-m-d") . ".log");
$logger->setFormatter($formatter);

$app = new Micro($di);

//Metodo el cual carga el formulario del integrante
//Verifica que que tenga creada la propuestas
$app->get('/buscar_documentacion', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo buscar_documentacion como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->get('modulo') . "&token=" . $request->get('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Validar si existe un participante como persona jurídica, con id usuario innner usuario_perfil
                $user_current = json_decode($token_actual->user_current, true);

                //Busco si tiene el perfil asociado de acuerdo al parametro
                if ($request->get('m') == "pn") {
                    $tipo_participante = "Persona Natural";
                    $usuario_perfil = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil = 6");
                }
                if ($request->get('m') == "pj") {
                    $tipo_participante = "Persona Jurídica";
                    $usuario_perfil = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil = 7");
                }
                if ($request->get('m') == "agr") {
                    $tipo_participante = "Agrupaciones";
                    $usuario_perfil = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil = 8");
                }

                if (isset($usuario_perfil->id)) {

                    //Consulto el participante inicial
                    $participante = Participantes::findFirst("usuario_perfil=" . $usuario_perfil->id . " AND tipo='Inicial' AND active=TRUE");

                    //Si existe el participante inicial con el perfil de acuerdo al parametro
                    if (isset($participante->id)) {

                        //Consulto la propuesta que esta relacionada con el participante
                        $sql_propuesta = "SELECT 
                                                par.*, 
                                                p.*
                                        FROM Propuestas AS p
                                            INNER JOIN Participantes AS par ON par.id=p.participante                                            
                                        WHERE
                                        p.convocatoria=" . $request->get('conv') . " AND par.usuario_perfil=" . $usuario_perfil->id . " AND par.tipo='Participante' AND par.participante_padre=" . $participante->id . "";

                        $propuesta = $app->modelsManager->executeQuery($sql_propuesta)->getFirst();

                        if (isset($propuesta->p->id)) {
                            //Creo el array de la propuesta
                            $array = array();
                            $array["propuesta"] = $propuesta->p->id;
                            $array["participante"] = $propuesta->par->id;

                            $conditions = ['convocatoria' => $request->get('conv'), 'active' => true];

                            //Se crea todo el array de documentos administrativos y tecnicos
                            $consulta_documentos_administrativos = Convocatoriasdocumentos::find(([
                                        'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                                        'bind' => $conditions,
                                        'order' => 'orden ASC',
                            ]));
                            foreach ($consulta_documentos_administrativos as $documento) {
                                if ($documento->getRequisitos()->tipo_requisito == "Administrativos") {
                                    if ($documento->etapa == "Registro") {
                                        $documentos_administrativos[$documento->id]["id"] = $documento->id;
                                        $documentos_administrativos[$documento->id]["requisito"] = $documento->getRequisitos()->nombre;
                                        $documentos_administrativos[$documento->id]["descripcion"] = $documento->descripcion;
                                        $documentos_administrativos[$documento->id]["archivos_permitidos"] = json_decode($documento->archivos_permitidos);
                                        $documentos_administrativos[$documento->id]["tamano_permitido"] = $documento->tamano_permitido;
                                        $documentos_administrativos[$documento->id]["orden"] = $documento->orden;
                                    }
                                }

                                if ($documento->getRequisitos()->tipo_requisito == "Tecnicos") {
                                    $documentos_tecnicos[$documento->id]["id"] = $documento->id;
                                    $documentos_tecnicos[$documento->id]["requisito"] = $documento->getRequisitos()->nombre;
                                    $documentos_tecnicos[$documento->id]["descripcion"] = $documento->descripcion;
                                    $documentos_tecnicos[$documento->id]["archivos_permitidos"] = json_decode($documento->archivos_permitidos);
                                    $documentos_tecnicos[$documento->id]["tamano_permitido"] = $documento->tamano_permitido;
                                    $documentos_tecnicos[$documento->id]["orden"] = $documento->orden;
                                }
                            }

                            $array["administrativos"] = $documentos_administrativos;

                            $array["tecnicos"] = $documentos_tecnicos;

                            //Registro la accion en el log de convocatorias
                            $logger->info('"token":"{token}","user":"{user}","message":"Retorna la información de la documentacion para el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_documentacion"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();

                            //Retorno el array
                            echo json_encode($array);
                        } else {
                            //Registro la accion en el log de convocatorias           
                            $logger->error('"token":"{token}","user":"{user}","message":"Debe crear la propuesta para el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_documentacion"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "crear_propuesta";
                            exit;
                        }
                    } else {
                        //Busco si tiene el perfil asociado de acuerdo al parametro
                        if ($request->get('m') == "pn") {
                            //Registro la accion en el log de convocatorias           
                            $logger->error('"token":"{token}","user":"{user}","message":"Debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_documentacion"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "crear_perfil_pn";
                            exit;
                        }
                        if ($request->get('m') == "pj") {
                            //Registro la accion en el log de convocatorias           
                            $logger->error('"token":"{token}","user":"{user}","message":"Debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_documentacion"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "crear_perfil_pj";
                            exit;
                        }
                        if ($request->get('m') == "agr") {
                            //Registro la accion en el log de convocatorias           
                            $logger->error('"token":"{token}","user":"{user}","message":"Debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_documentacion"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "crear_perfil_agr";
                            exit;
                        }
                    }
                } else {
                    //Busco si tiene el perfil asociado de acuerdo al parametro
                    if ($request->get('m') == "pn") {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"Debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_documentacion"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "crear_perfil_pn";
                        exit;
                    }
                    if ($request->get('m') == "pj") {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"Debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_documentacion"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "crear_perfil_pj";
                        exit;
                    }
                    if ($request->get('m') == "agr") {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"Debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_documentacion"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "crear_perfil_agr";
                        exit;
                    }
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo buscar_documentacion como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo buscar_documentacion como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo buscar_documentacion como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

//Metodo el cual carga el formulario del integrante
//Verifica que que tenga creada la propuestas
$app->get('/buscar_archivos', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo buscar_archivos como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->get('modulo') . "&token=" . $request->get('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Validar si existe un participante como persona jurídica, con id usuario innner usuario_perfil
                $user_current = json_decode($token_actual->user_current, true);

                $propuesta = Propuestas::findFirst("id=" . $request->get('propuesta') . "");

                if (isset($propuesta->id)) {
                    
                    $conditions = ['propuesta' => $propuesta->id,'convocatoriadocumento' => $request->get('documento'), 'active' => true];
                    //Se crea todo el array de archivos de la propuesta
                    $consulta_documentos_administrativos = Propuestasdocumentos::find(([
                                'conditions' => 'propuesta=:propuesta: AND active=:active: AND convocatoriadocumento=:convocatoriadocumento:',
                                'bind' => $conditions,
                                'order' => 'id ASC',
                    ]));
                    
                    //Registro la accion en el log de convocatorias
                    $logger->info('"token":"{token}","user":"{user}","message":"Retorna la información documento convocatoriadocumento para el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_archivos"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();

                    //Retorno el array
                    echo json_encode($consulta_documentos_administrativos);
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Debe crear la propuesta para el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_archivos"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "crear_propuesta";
                    exit;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo buscar_archivos como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo buscar_archivos como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo buscar_archivos como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

// Crear registro
$app->post('/guardar_archivo', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();
    $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo guardar_archivo como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPut('modulo') . "&token=" . $request->getPut('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Validar si existe un participante como persona jurídica, con id usuario innner usuario_perfil
                $user_current = json_decode($token_actual->user_current, true);

                $explode = explode(',', substr($request->getPut('srcData'), 5), 2);
                $data = $explode[1];
                $fileName = "c" . $request->getPost('convocatoria_padre_categoria') . "d" . $request->getPut('conv') . "u" . $user_current["id"] . "f" . date("YmdHis") . "." . $request->getPut("srcExt");
                $return = $chemistry_alfresco->newFile("/Sites/convocatorias/" . $request->getPut('conv') . "/propuestas/" . $request->getPut('propuesta'), $fileName, base64_decode($data), $request->getPut("srcType"));
                if (strpos($return, "Error") !== FALSE) {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error al crear el archivo (' . $request->getPut('srcName') . ') en el metodo guardar_archivo como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
                    $logger->close();
                    echo "error_archivo";
                } else {
                    $propuestasdocumentos = new Propuestasdocumentos();
                    $propuestasdocumentos->creado_por = $user_current["id"];
                    $propuestasdocumentos->fecha_creacion = date("Y-m-d H:i:s");
                    $propuestasdocumentos->active = true;
                    $propuestasdocumentos->propuesta = $request->getPut('propuesta');
                    $propuestasdocumentos->convocatoriadocumento = $request->getPut('documento');
                    $propuestasdocumentos->id_alfresco = $return;
                    $propuestasdocumentos->nombre = $request->getPut('srcName');
                    if ($propuestasdocumentos->save() === false) {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"Error al crear el archivo en la base de datos (' . $request->getPut('srcName') . ') en el metodo guardar_archivo como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
                        $logger->close();
                        echo "error_archivo";
                    } else {
                        echo $propuestasdocumentos->id;
                    }
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo guardar_archivo como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo guardar_archivo como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo buscar_documentacion como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

// Eliminar registro de los perfiles de las convocatorias
$app->delete('/delete/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));
        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_eliminar");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPut('modulo') . "&token=" . $request->getPut('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);
        
            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                // Consultar el registro
                $propuestasdocumentos = Propuestasdocumentos::findFirst(json_decode($id));                
                $propuestasdocumentos->active=false;
                
                if ($propuestasdocumentos->save($propuestasdocumentos) === false) {
                    echo "error";
                } else {
                    echo $propuestasdocumentos->id;
                }
            } else {
                echo "acceso_denegado";
            }           
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        echo "error_metodo";
    }
});

$app->post('/download_file', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);        
        
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {            
            echo $chemistry_alfresco->download($request->getPost('cod'));            
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        echo "error_metodo";
    }
}
);


try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage();
}
?>