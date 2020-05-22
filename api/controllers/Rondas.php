<?php
/*
*Cesar britto
*/
error_reporting(E_ALL);
ini_set('display_errors', '1');
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
        "host" => $config->database->host,"port" => $config->database->port,
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

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

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


                $ronda = new Convocatoriasrondas();
                $ronda->creado_por = $user_current["id"];
                $ronda->fecha_creacion = date("Y-m-d H:i:s");
                /**
                * Cesar Britto,21-05-2020
                * se ajusta para guardar la fecha de fin de evaluación con las horas
                */
                $post['fecha_fin_evaluacion'] =   $post['fecha_fin_evaluacion'].' 23:59:59';
                $ronda->active = true;
                $ronda->grupoevaluador = null;

                if ($ronda->save($post) === false) {

                    foreach ($ronda->getMessages() as $message) {
                      echo $message;
                    }

                    echo "error";
                } else {
                    echo $ronda->id;
                }


            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error_token";
        }

    } catch (Exception $ex) {
        // echo "error_metodo" .  $ex->getMessage().json_encode($ex->getTrace());
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
        if (isset($token_actual->id)) {

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
                $ronda = Convocatoriasrondas::findFirst(json_decode($id));
                $ronda->actualizado_por = $user_current["id"];
                /**
                * Cesar Britto,21-05-2020
                * se ajusta para guardar la fecha de fin de evaluación con las horas
                */
                $put['fecha_fin_evaluacion'] =   $put['fecha_fin_evaluacion'].' 23:59:59';
                $ronda->fecha_actualizacion = date("Y-m-d H:i:s");

                //si la ronda tiene estado null se puede editar, en caso contrario
                //quiere decir que ya tiene información asociada, por lo tanto no se puede modificar por interfaz
                if($ronda->estado === null){// Se quitan comillas al null

                    if ($ronda->save($put) === false) {
                        echo "error";
                    } else {
                        echo $id;
                    }
                }else{
                    return 'deshabilitado';
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
        if (isset($token_actual->id)) {

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
                $ronda = Convocatoriasrondas::findFirst('id = '.$id );

                //echo json_encode($ronda);

                //si la ronda tiene estado null se puede editar, en caso contrario
                //quiere decir que ya tiene información asociada, por lo tanto no se puede modificar por interfaz
                /**
                *Cesar Britto, 2020-05-12
                * Se ajusta la validación
                */
                if( $ronda->estado == null ){

                    if( $ronda->active == true ){
                        $ronda->active=false;
                        $retorna="No";
                    }else{
                        $ronda->active=true;
                        $retorna="Si";
                    }

                    if ($ronda->save() === false) {
                        return "error";
                    } else {
                        return (String)$retorna;
                    }

                }else{
                    return 'deshabilitado';
                }


            } else {
                return "acceso_denegado";
            }
        } else {
            return "error";
        }
    } catch (Exception $ex) {
      //return "error_metodo".$ex->getMessage();
        return "error_metodo";
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
        if (isset($token_actual->id)) {
            $ronda = Convocatoriasrondas::findFirst($id);
            if (isset($ronda->id)) {
                echo json_encode($ronda);
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
$app->get('/all_convocatoria', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $array =  array();
        $response =  array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {


          //validar si tiene $categorias
          $convocatoria = Convocatorias::findFirst($request->get('idcat'));

          array_push($array, $convocatoria->id);

          if( $convocatoria->tiene_categorias){

            $convocatorias = Convocatorias::find(
              [
                "convocatoria_padre_categoria = ".$request->get('idcat')
              ]
            );

            //id relacionados con las categorias(convocatoria)
            foreach ($convocatorias as $conv) {
              array_push($array, $conv->id);
            }

          }

          //resultado con filtro
          $rondas = Convocatoriasrondas::find(
              [
                  "convocatoria IN ({idConvocatoria:array}) AND nombre_ronda LIKE '%".$request->get("search")['value']."%'",
                  "order" => 'numero_ronda',
                  "limit" =>  $request->get('length'),
                  "offset" =>  $request->get('start'),
                  "bind" => [
                    "idConvocatoria" => $array
                  ],
                ]
            );

          foreach ($rondas as $ronda) {
                $ronda->actualizado_por = null;
                $ronda->creado_por = null;
                array_push($response, ['categoria'=>$ronda->convocatorias->nombre, 'ronda'=>$ronda]);
            }

          //resultado sin filtro
          $trondas = Convocatoriasrondas::find(
              [
                  "convocatoria IN ({idConvocatoria:array}) ",
                  "bind" => [
                    "idConvocatoria" => $array
                  ]
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

/*
 * Cesar Britto, 2020-01-17
 * Retorna información de id y nombre de las rondas de la convocatoria
 */
$app->get('/select_rondas', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $response =  array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Si existe consulto la convocatoria
            if( $request->get('convocatoria') )
            {

                $rondas =  Convocatoriasrondas::find(
                    [ ' convocatoria = '.$request->get('convocatoria')
                      .' AND active = true ',
                      ' order'=>'numero_ronda'
                    ]
                 );

                foreach ( $rondas as $ronda) {
                    array_push($response, ["id"=> $ronda->id, "nombre"=> $ronda->nombre_ronda ] );
                }

            }

            return json_encode($response);

        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        return "error_metodo".$ex->getMessage();
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
