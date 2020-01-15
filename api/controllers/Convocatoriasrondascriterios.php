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

// Crear registro
$app->post('/new', function () use ($app, $config) {

    try {

        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $total=0;

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl."Session/permiso_escritura");
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

                $criterio = new Convocatoriasrondascriterios();
                $criterio->creado_por = $user_current["id"];
                $criterio->fecha_creacion = date("Y-m-d H:i:s");
                $criterio->active = true;


                //Verificar que la suma de los puntajes no sea mayor a puntaje_maximo_criterios
                //se buscan los criterios activos relacionadso con la ronda
                $criterios = Convocatoriasrondascriterios::find(
                    [
                        "convocatoria_ronda = ".$request->getPost('convocatoria_ronda')." AND active = true",
                         "order" => 'orden ASC'
                    ]
                  );

                  //se busca el valor maximo que debe de tener la suma de los criterios
                $tabla_maestra= Tablasmaestras::findFirst("active=true AND nombre='puntaje_maximo_criterios'");

                $array_grupo_puntaje = array();

                //se hace la suma por grupo de criterios, encaso de que un criterio sea exclusivo,
                // solo se tienen en cuenta el valor del exclusivo. Se almacena el valor
                foreach ($criterios as $c) {
                    $array_grupo_puntaje[$c->grupo_criterio] = ($c->exclusivo ? $c->puntaje_maximo: ( $array_grupo_puntaje[$c->grupo_criterio]+$c->puntaje_maximo) );

                }

                // a la suma de los valores del grupo se le suma el valor del nuevo criterio,
                // se tienen en cue nta si es exclusivo
                $array_grupo_puntaje[ $post["grupo_criterio"] ] = ( ( $post["exclusivo"] == 'true' ) ? $post["puntaje_maximo"] :  ( $array_grupo_puntaje[$post["grupo_criterio"]] + $post["puntaje_maximo"] ) ) ;

                //Se suman los subtotales de los grupos, para calcuar el total
                foreach ($array_grupo_puntaje as $key => $value) {

                  $total = $total + $value;

                }

                //si el total es mayor que el puntaje_maximo_criterios se retorna error
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

                    return "error";
                } else {
                    echo $criterio->id;
                }


            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error_token";
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
                        "order" => 'orden ASC'
                    ]
                  );

                    //echo json_encode($criterios);

                  $tabla_maestra= Tablasmaestras::findFirst("active=true AND nombre='puntaje_maximo_criterios'");

                  foreach ($criterios as $c) {

                    //$total = $total + $c->puntaje_maximo;
                    if( $c->id != $put["id_registro_criterio"] ){

                       //echo "[id->".$c->id.",".$c->grupo_criterio."->".( ($c->exclusivo) ? $c->puntaje_maximo: $array_grupo_puntaje[$c->grupo_criterio]+$c->puntaje_maximo).",".($c->exclusivo)."]";

                      $array_grupo_puntaje[$c->grupo_criterio] = ($c->exclusivo) ? $c->puntaje_maximo: $array_grupo_puntaje[$c->grupo_criterio]+$c->puntaje_maximo;
                    }


                  }

                    $array_grupo_puntaje[ $put["grupo_criterio"] ] = ( $put["exclusivo"]== 'true' ) ? $put["puntaje_maximo"] :   $array_grupo_puntaje[ $put["grupo_criterio"] ]+ $put["puntaje_maximo"];


                    //echo "grupo--->>";

                  //  echo json_encode($array_grupo_puntaje);

                  /*foreach ($criterios as $c) {

                    $total = $total + $c->puntaje_maximo;

                    }
                    */


                    foreach ($array_grupo_puntaje as $key => $value) {

                      $total = $total + $value;

                    }

                    //echo " puntaje_maximo-->".$put["puntaje_maximo"];
                    //echo " total1-->".$total;
                    //$total = $total + $post["puntaje_maximo"];

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
                    return "error";
                } else {
                    return $id;
                }
            } else {
                return "acceso_denegado";
            }
        } else {
              return "error_token";
        }
    } catch (Exception $ex) {
        return "error_metodo";
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
                  //Verificar que la suma de los puntajes no sea mayor a puntaje_maximo_criterios
                  $criterios = Convocatoriasrondascriterios::find(
                      [
                          "convocatoria_ronda = ".$request->getPut('convocatoria_ronda')." AND active = true",
                          "order" => 'orden ASC'
                      ]
                    );

                  $tabla_maestra= Tablasmaestras::findFirst("active=true AND nombre='puntaje_maximo_criterios'");


                  foreach ($criterios as $c) {

                      $array_grupo_puntaje[$c->grupo_criterio] = ($c->exclusivo) ? $c->puntaje_maximo: $array_grupo_puntaje[$c->grupo_criterio]+$c->puntaje_maximo;
                  }

                  $array_grupo_puntaje[$criterio->grupo_criterio] = ($criterio->exclusivo) ? $criterio->puntaje_maximo : $array_grupo_puntaje[$criterio->grupo_criterio] + $criterio->puntaje_maximo;




                /*  foreach ($criterios as $c) {
                      $total = $total + $c->puntaje_maximo;
                  }
                  */

                  foreach ($array_grupo_puntaje as $key => $value) {

                    $total = $total + $value;

                  }

                //  $total = $total + $criterio->puntaje_maximo;

                  if( $total > $tabla_maestra->valor ){
                      return "error_puntaje";
                  }

                    $criterio->active=true;
                    $retorna="Si";
                }



                if ($criterio->save() === false) {
                  //Para auditoria en versión de pruebas
                  /*
                  foreach ($criterio->getMessages() as $message) {
                    echo $message;
                  }
                  */
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
      //  echo "error_metodo".$ex->getMessage();

      //Para auditoria en versión de pruebas
      echo "error_metodo". $ex->getMessage().json_encode($ex->getTrace());

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
