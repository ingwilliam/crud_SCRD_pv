<?php
/*
*Cesar britto
*/
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



// Recupera todos los registros
/*
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
                1 => 'a.descripcion',
            );

            $where .= " WHERE a.active=true";
            //Condiciones para la consulta

            if (!empty($request->get("search")['value'])) {
                $where .= " AND ( UPPER(" . $columns[0] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                $where .= " OR UPPER(" . $columns[1] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' )";
            }

            //Defino el sql del total y el array de datos
            $sqlTot = "SELECT count(*) as total FROM Entidades AS a";
            $sqlRec = "SELECT " . $columns[0] . ", " . $columns[1] . " , concat('<button type=\"button\" class=\"btn btn-warning\" onclick=\"form_edit(',a.id,')\"><span class=\"glyphicon glyphicon-edit\"></span></button><button type=\"button\" class=\"btn btn-danger\" onclick=\"form_del(',a.id,')\"><span class=\"glyphicon glyphicon-remove\"></span></button>') as acciones FROM Entidades AS a";

            //concatenate search sql if value exist
            if (isset($where) && $where != '') {

                $sqlTot .= $where;
                $sqlRec .= $where;
            }

            //Concateno el orden y el limit para el paginador2
            $sqlRec .= " ORDER BY " . $columns[$request->get('order')[0]['column']] . "   " . $request->get('order')[0]['dir'] . "  LIMIT " . $request->get('length') . " offset " . $request->get('start') ." ";

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
*/

// Crear registro
$app->post('/new', function () use ($app, $config) {

    try {

        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $total=0;

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl."Session/permiso_escritura");
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
                //$area = new Entidades();
                //$area->creado_por = $user_current["id"];
                //$area->fecha_creacion = date("Y-m-d H:i:s");
                //$area->active = true;

                $criterio = new Convocatoriasrondascriterios();
                $criterio->creado_por = $user_current["id"];
                $criterio->fecha_creacion = date("Y-m-d H:i:s");
                $criterio->active = true;


                  //Verificar que la suma de los puntajes no sea mayor a puntaje_maximo_criterios
                  $criterios = Convocatoriasrondascriterios::find(
                      [
                          "convocatoria_ronda = ".$request->getPut('convocatoria_ronda')." AND active = true",
                          ]
                    );

                      //echo json_encode($criterios);

                    $tabla_maestra= Tablasmaestras::findFirst("active=true AND nombre='puntaje_maximo_criterios'");

                    foreach ($criterios as $c) {

                      $total = $total + $c->puntaje_maximo;

                      }

                      $total = $total + $post["puntaje_maximo"];

                      if( $total > $tabla_maestra->valor ){
                          return "error_puntaje";
                      }


                if ($criterio->save($post) === false) {

                    //Para auditoria en versión de pruebas
                    /*
                    foreach ($criterio->getMessages() as $message) {
                      echo $message;
                    }
                    */

                    echo "error";
                } else {
                    echo $criterio->id;
                }


            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error";
        }

    } catch (Exception $ex) {
        //echo "error_metodo";

        //Para auditoria en versión de pruebas
        echo "error_metodo". $ex->getMessage().json_encode($ex->getTrace());
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
                $criterio = Convocatoriasrondascriterios::findFirst(json_decode($id));
                $criterio->actualizado_por = $user_current["id"];
                $criterio->fecha_actualizacion = date("Y-m-d H:i:s");


                //Verificar que la suma de los puntajes no sea mayor a puntaje_maximo_criterios
                $criterios = Convocatoriasrondascriterios::find(
                    [
                        "convocatoria_ronda = ".$request->getPut('convocatoria_ronda')." AND active = true",
                        ]
                  );

                    //echo json_encode($criterios);

                  $tabla_maestra= Tablasmaestras::findFirst("active=true AND nombre='puntaje_maximo_criterios'");

                  foreach ($criterios as $c) {

                    $total = $total + $c->puntaje_maximo;

                    }

                    $total = $total + $post["puntaje_maximo"];

                    if( $total > $tabla_maestra->valor ){
                        return "error_puntaje";
                    }


                if ($criterio->save($put) === false) {


                    //Para auditoria en versión de pruebas
                    /*
                    foreach ($criterio->getMessages() as $message) {
                      echo $message;
                    }
                    */
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
                $criterio = Convocatoriasrondascriterios::findFirst(json_decode($id));
                if($criterio->active==true)
                {
                    $criterio->active=false;
                    $retorna="No";
                }
                else
                {
                    $criterio->active=true;
                    $retorna="Si";
                }

                if ($criterio->save() === false) {
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
        echo "error_metodo".$ex->getMessage();
    }
});

//Busca el registro

$app->get('/search/{id:[0-9]+}', function ($id) use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            $criterio = Convocatoriasrondascriterios::findFirst($id);
            if (isset($criterio->id)) {
                echo json_encode($criterio);
            } else {
                echo "error";
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        echo "error_metodo";
    }
}
);




// Recupera todos los registros
$app->get('/all_criterios_ronda', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $array =  array();
        $response =  array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

          /*
          $convocatorias = Convocatorias::find(
            [
              "convocatoria_padre_categoria = ".$request->get('idcat')
            ]

          );

          //id relacionados con las categorias(convocatoria)
          foreach ($convocatorias as $convocatoria) {
            array_push($array, $convocatoria->id);
          }

        //  echo "string".print_r($array);

        */
          //resultado con filtro
          $criterios = Convocatoriasrondascriterios::find(
              [
                  "convocatoria_ronda = ".$request->get('idRonda')." AND descripcion_criterio LIKE '%".$request->get("search")['value']."%'",
                  "order" => 'orden',
                  "limit" =>  $request->get('length'),
                  "offset" =>  $request->get('start'),

                ]
            );


            foreach ($criterios as $criterio) {
                  $criterio->actualizado_por = null;
                  $criterio->creado_por = null;
                  array_push($response, $criterio);
              }


            //resultado sin filtro
            $trondas = Convocatoriasrondascriterios::find(
                [
                    "convocatoria_ronda  = ".$request->get('idRonda'),
                ]
              );

            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($trondas->count()),
                "recordsFiltered" => intval($trondas->count()),
                "data" => $response   // total data array
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


// Recupera todos los registros
$app->get('/criterios_ronda', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $array =  array();
        $response =  array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

          //resultado con filtro
          $criterios = Convocatoriasrondascriterios::find(
              [
                  "convocatoria_ronda = ".$request->get('idRonda')." AND descripcion_criterio LIKE '%".$request->get("search")['value']."%'",
                ]
            );


            foreach ($criterios as $criterio) {
                  $criterio->actualizado_por = null;
                  $criterio->creado_por = null;
                  array_push($response, $criterio);
              }

           //retorno el array en json
           echo json_encode($response);
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



try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage();
}
?>
