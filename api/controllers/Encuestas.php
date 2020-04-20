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

$config = new ConfigIni('../config/config.ini');

// Registramos un autoloader
$loader = new Loader();

$loader->registerDirs(
        [
            APP_PATH . '/models/',
        ]
);

$loader->register();

// Crear un DI
$di = new FactoryDefault();



//Set up the database service
$di->set('db', function () use ($config) {
    return new DbAdapter(
            array(
        "host" => $config->database->host, "port" => $config->database->port,
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

// Recupera todos los registros
$app->get('/all', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Defino columnas para el orden desde la tabla html
            $columns = array(
                0 => 'e.nombre',
                1 => 'e.anio',
            );

            $where .= " INNER JOIN Programas AS pro ON pro.id=e.programa";
            $where .= " WHERE e.active IN (true,false)";
            //Condiciones para la consulta

            if (!empty($request->get("search")['value'])) {
                $where .= " AND ( UPPER(" . $columns[0] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' )";
            }

            //Defino el sql del total y el array de datos
            $sqlTot = "SELECT count(*) as total FROM Encuestas AS e";
            $sqlRec = "SELECT e.tipo," . $columns[0] . "," . $columns[1] . ",pro.nombre AS programa , concat('<button type=\"button\" style=\"margin-right: 5px\" class=\"btn btn-warning\" onclick=\"form_edit(',e.id,')\"><span class=\"glyphicon glyphicon-edit\"></span></button><button type=\"button\" class=\"btn btn-warning\" onclick=\"form_edit_param(',e.id,')\"><span class=\"glyphicon glyphicon-list\"></span></button> ') as acciones,concat('<input title=\"',e.id,'\" type=\"checkbox\" class=\"check_activar_',e.active,' activar_categoria\" />') as activar_registro FROM Encuestas AS e";

            //concatenate search sql if value exist
            if (isset($where) && $where != '') {

                $sqlTot .= $where;
                $sqlRec .= $where;
            }

            //Concateno el orden y el limit para el paginador
            $sqlRec .= " ORDER BY " . $columns[$request->get('order')[0]['column']] . "   " . $request->get('order')[0]['dir'] . "  LIMIT " . $request->get('length') . " offset " . $request->get('start') . " ";

            //ejecuto el total de registros actual
            $totalRecords = $app->modelsManager->executeQuery($sqlTot)->getFirst();

            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($totalRecords["total"]),
                "recordsFiltered" => intval($totalRecords["total"]),
                "data" => $app->modelsManager->executeQuery($sqlRec)   // total data array
            );
            //retorno el array en json
            echo json_encode($json_data);
        } else {
            //retorno el array en json null
            echo json_encode(null);
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        echo json_encode($ex->getMessage());
    }
}
);

// Crear registro
$app->post('/new', function () use ($app, $config) {
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
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPut('modulo') . "&token=" . $request->getPut('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $user_current = json_decode($token_actual->user_current, true);
                $post = $app->request->getPost();
                $encuesta = new Encuestas();
                $encuesta->creado_por = $user_current["id"];
                $encuesta->fecha_creacion = date("Y-m-d H:i:s");
                $encuesta->active = true;
                if ($encuesta->save($post) === false) {
                    echo "error";
                } else {
                    echo $encuesta->id;
                }
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo";
    }
}
);

// Editar registro
$app->put('/edit/{id:[0-9]+}', function ($id) use ($app, $config) {
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
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPut('modulo') . "&token=" . $request->getPut('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $user_current = json_decode($token_actual->user_current, true);
                $put = $app->request->getPut();
                // Consultar el usuario que se esta editando
                $encuesta = Encuestas::findFirst(json_decode($id));
                $encuesta->actualizado_por = $user_current["id"];
                $encuesta->fecha_actualizacion = date("Y-m-d H:i:s");
                if ($encuesta->save($put) === false) {
                    echo "error";
                } else {
                    echo $id;
                }
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo";
    }
}
);

// Eliminar registro
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
                // Consultar el usuario que se esta editando
                $user = Encuestas::findFirst(json_decode($id));
                $user->active = $request->getPut('active');
                if ($user->save($user) === false) {
                    echo "error";
                } else {
                    echo "ok";
                }
            } else {
                echo "acceso_denegado";
            }

            exit;
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo";
    }
});

//Busca el registro
$app->get('/search', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            $encuesta = new Encuestas();
            if ($request->get('id') > 0) {
                $encuesta = Encuestas::findFirst($request->get('id'));
            }

            $array_json["encuesta"] = $encuesta;
            for ($i = date("Y") + 1; $i >= 2016; $i--) {
                $array_json["anios"][] = $i;
            }
            $array_json["programas"] = Programas::find("active=true");
            echo json_encode($array_json);
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        echo "error_metodo";
    }
}
);

//Modulo buscador
$app->get('/generar_encuesta', function () use ($app, $config, $logger) {

    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Consulto el usuario actual
        $user_current = json_decode($token_actual->user_current, true);
        
        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo generar_encuesta con el fin de cargar el formulario de encuesta"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
        
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

                $propuesta = Propuestas::findFirst("id=".$request->get('id'));
                $array["propuesta"] = $propuesta;
                
                $propuestaparametros = Encuestaspropuestasparametros::find("propuesta=" . $propuesta->id);
                //Recorro los valores de los parametros con el fin de ingresarlos al formulario
                $array_values_params=array();
                foreach ($propuestaparametros as $pp) {
                    $array_values_params["parametro[" . $pp->encuestaparametro . "]"] = $pp->valor;
                }
                
                
                $array["convocatoria_nombre"] = $propuesta->getConvocatorias()->nombre;
                $array["programa_nombre"] = $propuesta->getConvocatorias()->getProgramas()->nombre;
                $array["entidad_nombre"] = $propuesta->getConvocatorias()->getEntidades()->nombre;
                //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria                
                if ($propuesta->getConvocatorias()->convocatoria_padre_categoria > 0) {                
                    $array["convocatoria_nombre"] = $propuesta->getConvocatorias()->getConvocatorias()->nombre." - ".$propuesta->getConvocatorias()->nombre;                    
                }
                $encuesta= Encuestas::findFirst("active=TRUE AND tipo='Propuestas' AND programa=".$propuesta->getConvocatorias()->programa);
                $array["encuesta"] = $encuesta;
                $array["parametros"] = $encuesta->getEncuestasparametros();
                $array["array_values_params"] = $array_values_params;
                $array["total_values_params"] = count($propuestaparametros);
                
                //Creo los parametros obligatorios del formulario
                $options = array(
                    "fields" => array()
                );
                
                foreach ($array["parametros"] as $k => $v) {
                    if ($v->obligatorio) {
                        $options["fields"] += array(
                            "parametro[" . $v->id . "]" => array(
                                "validators" => array(
                                    "notEmpty" => array("message" => "El campo es requerido.")
                                )
                            )
                        );
                    }
                }

                $array["validator"] = $options;
                
                $logger->info('"token":"{token}","user":"{user}","message":"Retorna la información en el metodo generar_encuesta con el fin de cargar el formulario de la encuesta"', ['user' => $user_current["username"], 'token' => $request->get('token')]);

                echo json_encode($array);
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo generar_encuesta para cargar el formulario de encuesta"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo generar_encuesta para cargar el formulario de busqueda"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo modulo_buscador_propuestas para cargar el formulario de busqueda' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->get('/all_params', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Defino columnas para el orden desde la tabla html
            $columns = array(
                0 => 'ca.label',
                1 => 'ca.tipo_parametro',
                2 => 'ca.orden',
                3 => 'en.nombre',
                4 => 'ca.valores',
            );

            //Condiciones basicas
            $where .= " INNER JOIN Encuestas AS en ON en.id=ca.encuesta";
            $where .= " WHERE ca.active IN (true,false) ";

            //Condiciones para la consulta
            if (!empty($request->get("search")['value'])) {
                $where .= " AND ( UPPER(" . $columns[0] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                $where .= " OR UPPER(" . $columns[1] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                $where .= " OR UPPER(" . $columns[3] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' )";
            }

            //Defino el sql del total y el array de datos
            $sqlTot = "SELECT count(*) as total FROM Encuestasparametros AS ca";
            $sqlRec = "SELECT " . $columns[0] . " ," . $columns[1] . "," . $columns[2] . "," . $columns[3] . " AS encuesta,ca.valores,concat('<input title=\"',ca.id,'\" type=\"checkbox\" class=\"check_activar_',ca.active,' activar_registro\" />') as activar_registro , concat('<button title=\"',ca.id,'\" type=\"button\" class=\"btn btn-warning cargar_formulario\" data-toggle=\"modal\" data-target=\"#nuevo_evento\"><span class=\"glyphicon glyphicon-edit\"></span></button>') as acciones FROM Encuestasparametros AS ca";

            //concatenate search sql if value exist
            if (isset($where) && $where != '') {

                $sqlTot .= $where;
                $sqlRec .= $where;
            }

            //Concateno el orden y el limit para el paginador
            $sqlRec .= " ORDER BY ca.orden   ASC  LIMIT " . $request->get('length') . " offset " . $request->get('start') . " ";

            //ejecuto el total de registros actual
            $totalRecords = $app->modelsManager->executeQuery($sqlTot)->getFirst();

            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($totalRecords["total"]),
                "recordsFiltered" => intval($totalRecords["total"]),
                "data" => $app->modelsManager->executeQuery($sqlRec)   // total data array
            );
            //retorno el array en json
            echo json_encode($json_data);
        } else {
            //retorno el array en json null
            echo json_encode(null);
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        echo json_encode($ex->getMessage());
    }
}
);

// Crear registro
$app->post('/new_param', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPost('modulo') . "&token=" . $request->getPost('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $user_current = json_decode($token_actual->user_current, true);
                $post = $app->request->getPost();

                //Valido si el usuario selecciono una categoria, con el fin de asignarle la convocatoria principal
                if ($post["convocatoria"] == "") {
                    $post["convocatoria"] = $post["convocatoria_padre_categoria"];
                }

                $convocatoriaspropuestasparametros = new Encuestasparametros();
                $convocatoriaspropuestasparametros->creado_por = $user_current["id"];
                $convocatoriaspropuestasparametros->fecha_creacion = date("Y-m-d H:i:s");
                $convocatoriaspropuestasparametros->active = true;
                if ($convocatoriaspropuestasparametros->save($post) === false) {
                    echo "error";
                } else {
                    echo $convocatoriaspropuestasparametros->id;
                }
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        echo "error_metodo" . $ex->getMessage();
    }
}
);

// Crear registro
$app->post('/new_encuesta_param', function () use ($app, $config, $logger) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Consulto el usuario actual
        $user_current = json_decode($token_actual->user_current, true);
                
        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPost('modulo') . "&token=" . $request->getPost('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {                
                $post = $app->request->getPost();

                //Recorrmos los parametros dinamicos                    
                foreach ($post["parametro"] as $k => $v) {
                    //Consulto si exite el parametro a la propuestas
                    $parametro_actual = Encuestaspropuestasparametros::findFirst("encuestaparametro=" . $k . " AND propuesta = " . $request->getPost('propuesta'));
                    if (isset($parametro_actual->id)) {
                        $parametro = $parametro_actual;
                    } else {
                        $parametro = new Encuestaspropuestasparametros();
                    }

                    //Cargo lo valores actuales
                    $array_save = array();
                    $array_save["encuestaparametro"] = $k;
                    $array_save["propuesta"] =  $request->getPost('propuesta');
                    $array_save["valor"] = $v;

                    //Valido si existe para relacionar los campos de usuario
                    if (isset($parametro->id)) {
                        $parametro->actualizado_por = $user_current["id"];
                        $parametro->fecha_actualizacion = date("Y-m-d H:i:s");
                    } else {
                        $parametro->creado_por = $user_current["id"];
                        $parametro->fecha_creacion = date("Y-m-d H:i:s");
                    }

                    //Guardo los parametros de la convocatoria
                    if ($parametro->save($array_save) == false) {
                        foreach ($parametro->getMessages() as $message) {
                            $logger->info('"token":"{token}","user":"{user}","message":"'.$message.'" (' . $message . ').', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        }
                    } else {
                        $logger->info('"token":"{token}","user":"{user}","message":"Se edito con exito el parametro (' . $parametro->id . ') en la propuesta (' . $request->getPost('propuesta') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    }
                }
                
                //Registro la accion en el log de convocatorias
                $logger->info('"token":"{token}","user":"{user}","message":"Se edito con exito la encuesta (' . $post["id"] . ') como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();
                
                echo $request->getPost('propuesta');
                
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        echo "error_metodo" . $ex->getMessage();
    }
}
);

//Busca el registro
$app->get('/search_param', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            //Si existe consulto la convocatoria
            if ($request->get('id')) {
                $convocatoriaspropuestasparametros = Encuestasparametros::findFirst($request->get('id'));
            } else {
                $convocatoriaspropuestasparametros = new Encuestasparametros();
            }
            //Creo todos los array del registro
            $array["convocatoriaspropuestasparametros"] = $convocatoriaspropuestasparametros;

            //Creo los tipos de documentos para anexar
            $tabla_maestra = Tablasmaestras::findFirst("active=true AND nombre='tipos_parametros'");
            $array["tipo_parametro"] = explode(",", $tabla_maestra->valor);

            //Retorno el array
            echo json_encode($array);
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        echo "error_metodo";
    }
}
);

// Editar registro
$app->post('/edit_param/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPost('modulo') . "&token=" . $request->getPost('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $user_current = json_decode($token_actual->user_current, true);
                $post = $app->request->getPost();

                // Consultar el usuario que se esta editando
                $convocatoriaspropuestasparametros = Encuestasparametros::findFirst(json_decode($id));
                $convocatoriaspropuestasparametros->actualizado_por = $user_current["id"];
                $convocatoriaspropuestasparametros->fecha_actualizacion = date("Y-m-d H:i:s");

                if ($convocatoriaspropuestasparametros->save($post) === false) {
                    echo "error";
                } else {
                    echo $id;
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
}
);

// Eliminar registro de los perfiles de las convocatorias
$app->delete('/delete_param/{id:[0-9]+}', function ($id) use ($app, $config) {
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
                $convocatoriaspropuestasparametros = Encuestasparametros::findFirst(json_decode($id));
                if ($convocatoriaspropuestasparametros->active == true) {
                    $convocatoriaspropuestasparametros->active = false;
                    $retorna = "No";
                } else {
                    $convocatoriaspropuestasparametros->active = true;
                    $retorna = "Si";
                }

                if ($convocatoriaspropuestasparametros->save($convocatoriaspropuestasparametros) === false) {
                    echo "error";
                } else {
                    echo $retorna;
                }
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo" . $ex->getMessage();
    }
});

try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage();
}
?>