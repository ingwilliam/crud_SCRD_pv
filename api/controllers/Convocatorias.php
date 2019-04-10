<?php

//error_reporting(E_ALL);
//ini_set('display_errors', '1');
use Phalcon\Loader;
use Phalcon\Mvc\Micro;
use Phalcon\Di\FactoryDefault;
use Phalcon\Db\Adapter\Pdo\Postgresql as DbAdapter;
use Phalcon\Config\Adapter\Ini as ConfigIni;
use Phalcon\Http\Request;

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
        "host" => $config->database->host,
        "username" => $config->database->username,
        "password" => $config->database->password,
        "dbname" => $config->database->name
            )
    );
});

$app = new Micro($di);

// Recupera todos las areas seleccionados de un usuario determinado
$app->get('/select_user/{id:[0-9]+}', function ($id) use ($app, $config) {

    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

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
                $phql = 'SELECT p.id,p.nombre,up.id AS checked FROM Convocatorias AS p LEFT JOIN Usuariosareas AS up ON p.id = up.area AND up.usuario=' . $id . ' WHERE p.active = true ORDER BY p.nombre';

                $areas_usuario = $app->modelsManager->executeQuery($phql);

                echo json_encode($areas_usuario);
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

/* Verificar si un usuario puede cambiar el estado a una convocatoria
 * @param estado 1 Creada, 2 Visto bueno, 3 Verificada, 4 Aprobada, 5 Publicada 
 */
$app->get('/verificar_estado', function () use ($app, $config) {

    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            //Consulto el usuario actual
            $user_current = json_decode($token_actual->user_current, true);
            /* Perfiles de los usuarios
             * 11 Crear convocatorias, 12 Visto bueno a las convocatorias, 13 Verificar convocatorias, 14 Aprobar convocatorias, 15 Publicar convocatorias
             */
            $usuariosperfiles = array();
            switch ($request->get('estado')) {
                case 1:
                    $usuariosperfiles = Usuariosperfiles::findFirst("usuario=".$user_current["id"]." AND perfil = 11");
                    break;
                case 2:
                    $usuariosperfiles = Usuariosperfiles::findFirst("usuario=".$user_current["id"]." AND perfil = 12");
                    break;
                case 3:
                    $usuariosperfiles = Usuariosperfiles::findFirst("usuario=".$user_current["id"]." AND perfil = 13");
                    break;
                case 4:
                    $usuariosperfiles = Usuariosperfiles::findFirst("usuario=".$user_current["id"]." AND perfil = 14");
                    break;
                case 5:
                    $usuariosperfiles = Usuariosperfiles::findFirst("usuario=".$user_current["id"]." AND perfil = 15");
                    break;
            }
            echo json_encode($usuariosperfiles->id);
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo";
    }
}
);

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
                0 => 'a.nombre',
            );

            $where .= " WHERE a.active=true";
            //Condiciones para la consulta

            if (!empty($request->get("search")['value'])) {
                $where .= " AND ( UPPER(" . $columns[0] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' )";
            }

            //Defino el sql del total y el array de datos
            $sqlTot = "SELECT count(*) as total FROM Convocatorias AS a";
            $sqlRec = "SELECT " . $columns[0] . " , concat('<button type=\"button\" class=\"btn btn-warning\" onclick=\"form_edit_page(1,',a.id,')\"><span class=\"glyphicon glyphicon-edit\"></span></button><button type=\"button\" class=\"btn btn-danger\" onclick=\"form_del(',a.id,')\"><span class=\"glyphicon glyphicon-remove\"></span></button>') as acciones FROM Convocatorias AS a";

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
        echo json_encode(null);
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
                $convocatoria = new Convocatorias();
                $convocatoria->creado_por = $user_current["id"];
                $convocatoria->fecha_creacion = date("Y-m-d H:i:s");
                $convocatoria->active = true;
                $convocatoria->estado = 1;
                if ($convocatoria->save($post) === false) {
                    echo "error";
                } else {
                    echo $convocatoria->id;
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
                $convocatoria = Convocatorias::findFirst(json_decode($id));
                $convocatoria->actualizado_por = $user_current["id"];
                $convocatoria->fecha_actualizacion = date("Y-m-d H:i:s");
                if($put["numero_estimulos"]=="")
                {
                    unset($put["numero_estimulos"]);
                }                
                if ($convocatoria->save($put) === false) {
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
        echo "error_metodo".$ex->getMessage();
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
                $user = Convocatorias::findFirst(json_decode($id));
                $user->active = false;
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
            //Si existe consulto la convocatoria
            if($request->get('id'))
            {    
                $convocatoria = Convocatorias::findFirst($request->get('id'));
            }
            else 
            {
                $convocatoria = new Convocatorias();
            }
            //Creo todos los array de la convocatoria
            $array["convocatoria"]=$convocatoria;
            $array["programas"]= Programas::find("active=true");
            $array["tipos_participantes"] = $app->modelsManager->executeQuery("SELECT Tiposparticipantes.id,Tiposparticipantes.nombre,Convocatoriasparticipantes.active,Convocatoriasparticipantes.descripcion_perfil AS descripcion_cp,Convocatoriasparticipantes.id AS id_cp  FROM Tiposparticipantes LEFT JOIN Convocatoriasparticipantes ON Convocatoriasparticipantes.tipo_participante = Tiposparticipantes.id WHERE Tiposparticipantes.active=true AND Tiposparticipantes.id <> 4");                                   
            $array["coberturas"]= Coberturas::find("active=true");            
            $array["localidades"]= Localidades::find("active=true");
            $array["upzs"]=array();
            $array["barrios"]=array();
            if(isset($convocatoria->id))
            {
                $array["modalidades"]= Modalidades::find("active=true AND programa=".$convocatoria->programa);
                $array["tipos_participantes"] = $app->modelsManager->executeQuery("SELECT Tiposparticipantes.id,Tiposparticipantes.nombre,Convocatoriasparticipantes.active,Convocatoriasparticipantes.descripcion_perfil AS descripcion_cp,Convocatoriasparticipantes.id AS id_cp  FROM Tiposparticipantes LEFT JOIN Convocatoriasparticipantes ON Convocatoriasparticipantes.tipo_participante = Tiposparticipantes.id WHERE Tiposparticipantes.active=true AND Tiposparticipantes.id <> 4 AND Convocatoriasparticipantes.convocatoria= ".$convocatoria->id);
                $array["perfiles_jurados"]= Convocatoriasparticipantes::find(['convocatoria = '.$convocatoria->id.' AND tipo_participante=4','order' => 'orden']);
                $array["upzs"]= Upzs::find("active=true AND localidad="+$convocatoria->localidad);
                $array["barrios"]= Barrios::find("active=true AND localidad="+$convocatoria->localidad);
            }             
            $array["enfoques"]= Enfoques::find("active=true");
            $array["lineas_estrategicas"]= Lineasestrategicas::find("active=true");
            $array["areas"]= Areas::find("active=true");            
            $tabla_maestra= Tablasmaestras::find("active=true AND nombre='cantidad_perfil_jurado'");            
            $array["cantidad_perfil_jurados"] = explode(",", $tabla_maestra[0]->valor);
            $array["tipos_convenios"]= Tiposconvenios::find("active=true");
            $array["tipos_estimulos"]= Tiposestimulos::find("active=true");
            $array["entidades"]= Entidades::find("active=true");
            $array["areas_conocimientos"]= Areasconocimientos::find("active=true AND id<>9");
            $array["niveles_educativos"]= Niveleseducativos::find("active=true");
            $array["estados"]= Estados::find("active=true AND tipo_estado='convocatorias' AND id<>5 ORDER BY orden");            
            $array["distribuciones_bolsas"]= $convocatoria->getConvocatoriasrecursos([
                                                                                        'tipo_recurso = :tipo_recurso:',
                                                                                        'bind' => [
                                                                                            'tipo_recurso' => 'Bolsa'
                                                                                        ],
                                                                                        'order'      => 'orden ASC',
                                                                                    ]);
            $array["distribuciones_especies"]= $convocatoria->getConvocatoriasrecursos([
                                                                                        'tipo_recurso = :tipo_recurso:',
                                                                                        'bind' => [
                                                                                            'tipo_recurso' => 'Especie'
                                                                                        ],
                                                                                        'order'      => 'orden ASC',
                                                                                    ]);
            $array["recursos_no_pecunarios"]= Recursosnopecuniarios::find("active=true");
            for($i = date("Y"); $i >= 2016; $i--){
                $array["anios"][] = $i;
            } 
            echo json_encode($array);            
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        echo "error_metodo".$ex->getMessage();
    }
}
);

//Busca el registro
$app->get('/load_search', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            $array=array();
            for($i = date("Y"); $i >= 2016; $i--){
                $array["anios"][] = $i;
            }            
            $array["entidades"]= Entidades::find("active = true");
            $array["areas"]= Areas::find("active = true");
            $array["lineas_estrategicas"]= Lineasestrategicas::find("active = true");
            $array["enfoques"]=Enfoques::find("active = true");
            $array["total_creadas"] = Convocatorias::count("estado =1 AND active = true");
            $array["total_vistos_buenos"] = Convocatorias::count("estado =2 AND active = true");
            $array["total_verificadas"] = Convocatorias::count("estado =3 AND active = true");
            $array["total_aprobadas"] = Convocatorias::count("estado =4 AND active = true");
            $array["total_publicadas"] = Convocatorias::count("estado =5 AND active = true");
            $array["estados_convocatorias"] = Estados::find(
                                                            array(
                                                                "tipo_estado = 'convocatorias' AND active = true",
                                                                "order" => "orden"
                                                            )
                                                            );
            echo json_encode($array);
        } else {
            echo "error";
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