<?php

//error_reporting(E_ALL);
//ini_set('display_errors', '1');
use Phalcon\Loader;
use Phalcon\Mvc\Micro;
use Phalcon\Di\FactoryDefault;
use Phalcon\Db\Adapter\Pdo\Postgresql as DbAdapter;
use Phalcon\Config\Adapter\Ini as ConfigIni;
use Phalcon\Http\Request;
use Phalcon\Mvc\Model\Query;

// Definimos algunas rutas constantes para localizar recursos
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH);

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

$app = new Micro($di);

// Recupera todos las modalidades dependiendo el programa
$app->get('/select', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            $array = Participantes::find("active = true");
            echo json_encode($array);
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo" . $ex->getMessage();
    }
}
);

// Crear registro
$app->post('/new', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //$chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

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

            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                $user_current = json_decode($token_actual->user_current, true);

                //cunsulta si el usuario tiene datos de participante, validando el numero de documento
                //el tipo de documento y el rol de jurado.
                $participantes = Participantes::query()
                  ->join("Usuariosperfiles")
                  ->where(" tipo_documento = ".$request->getPost('tipo_documento')." AND numero_documento = '".$request->getPost('numero_documento')."' AND ( Usuariosperfiles.perfil = 17 or Usuariosperfiles.perfil = 16 )")
                  ->execute();

                // Si hay mayor o igual a 1 registro, procede a validar
                // en caso contrario crea nuevos registros
                if( $participantes->count() >= 1 ){

                  return "error_duplicado";

                }else{

                    //creo el usuario_perfil
                    $usuario_perfil =  new Usuariosperfiles();
                    $usuario_perfil->usuario = $user_current["id"];
                    $usuario_perfil->perfil = 17;

                    if ($usuario_perfil->save() === false) {
                        echo "error";

                        foreach ($participante->getMessages() as $message) {
                             echo $message;
                           }

                    } else {

                          if( $usuario_perfil->id ){

                            $post = $app->request->getPost();

                            $participante = new Participantes();
                            $participante->creado_por = $user_current["id"];
                            $participante->fecha_creacion = date("Y-m-d H:i:s");
                            $participante->active = true;
                            $participante->tipo  = 'Inicial';
                            $participante->usuario_perfil = $usuario_perfil->id;


                            if ($participante->save($post) === false) {
                                echo "error";

                                foreach ($participante->getMessages() as $message) {
                                     echo $message;
                                   }

                            } else {

                                echo $participante->id;

                              }

                            }

                        }

                    }

            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        echo "error_metodo" .  $ex->getMessage().json_encode($ex->getTrace());
    }
}
);

// Editar registro
$app->post('/edit/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //$chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

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

                $user_current = json_decode($token_actual->user_current, true);

                //cunsulta si el usuario tiene datos de participante, validando el numero de documento
                //el tipo de documento y el rol de jurado.
                $participantes = Participantes::query()
                  ->join("Usuariosperfiles")
                  ->where(" tipo_documento = ".$request->getPost('tipo_documento')." AND numero_documento = '".$request->getPost('numero_documento')."' AND  Usuariosperfiles.perfil = 17  ")
                  ->execute();

                // Si hay mayor o igual a 1 registro, procede a validar
                // en caso contrario crea nuevos registros
                if( $participantes->count() >= 1 ){

                  //consulta si el usuario que ya tiene el perfil de jurado
                  $usuario_perfil  = Usuariosperfiles::findFirst(
                    [
                      " usuario = ".$user_current["id"]." AND perfil =17"
                    ]
                  );

                  //si el usuario actual tiene rol de jurado y tiene datos de participante, los actualiza.
                  //en caso contrario retorna error
                  if( $usuario_perfil->id ){
                      $post = $app->request->getPost();

                   $participante = Participantes::findFirst(
                      [
                        " usuario_perfil = ".$usuario_perfil->id

                      ]
                    );

                    $participante->actualizado_por = $user_current["id"];
                    $participante->fecha_actualizacion = date("Y-m-d H:i:s");
                    $participante->active = true;

                    if ($participante->save($post) === false) {
                        echo "error";

                        foreach ($participante->getMessages() as $message) {
                             echo $message;
                           }

                    }


                  }else{
                    return "error";
                  }


                }else{

                        return "error";

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

//Busca el registro
$app->get('/search', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));


        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);

            //Si existe esta definida la variable id del Request, consulto el registro
            if ($request->get('id')) {
                $participante = Participantes::findFirst($request->get('id'));
            } else if( $user_current["id"]){
                  //Si existe esta definida la variable id en el token_actual, consulto el registro

                  //consulto si el usuario que ya tiene el perfil de jurado
                  $usuario_perfil  = Usuariosperfiles::findFirst(
                    [
                      " usuario = ".$user_current["id"]." AND perfil =17"
                    ]
                  );

                  if( $usuario_perfil->id ){
                   $participante = Participantes::findFirst(
                      [
                        " usuario_perfil = ".$usuario_perfil->id." AND active = true "
                      ]
                    );
                  }else{

                     $usuario = Usuarios::findFirst($user_current["id"]);

                     $participante = new Participantes();
                     $participante->primer_nombre = $usuario->primer_nombre;
                     $participante->segundo_nombre = $usuario->segundo_nombre;
                     $participante->primer_apellido = $usuario->primer_apellido;
                     $participante->segundo_apellido = $usuario->segundo_apellido;
                     $participante->correo_electronico = $usuario->username;
                  }


            }else{
                //Se crea un objeto inicial
                $participante = new Participantes();
            }



            //Creo los array de los select del formulario
            $array["tipo_documento"]= Tiposdocumentos::find("active=true");
            $array["sexo"]= Sexos::find("active=true");
            $array["orientacion_sexual"]= Orientacionessexuales::find("active=true");
            $array["identidad_genero"]= Identidadesgeneros::find("active=true");
            $array["grupo_etnico"]= Gruposetnicos::find("active=true");

            $array_ciudades=array();
            foreach( Ciudades::find("active=true") as $value )
            {
                $array_ciudades[]=array("id"=>$value->id,"label"=>$value->nombre." - ".$value->getDepartamentos()->nombre." - ".$value->getDepartamentos()->getPaises()->nombre,"value"=>$value->nombre);

                if($participante->ciudad_nacimiento == $value->id ){
                  $participante->ciudad_nacimiento = array("id"=>$value->id,"label"=>$value->nombre." - ".$value->getDepartamentos()->nombre." - ".$value->getDepartamentos()->getPaises()->nombre,"value"=>$value->nombre);
                }

                if($participante->ciudad_residencia == $value->id ){
                  $participante->ciudad_residencia = array("id"=>$value->id,"label"=>$value->nombre." - ".$value->getDepartamentos()->nombre." - ".$value->getDepartamentos()->getPaises()->nombre,"value"=>$value->nombre);
                }
            }
            $array["ciudad"]=$array_ciudades;
            $array_barrios=array();
            foreach( Barrios::find("active=true") as $value )
            {
                $array_barrios[]=array("id"=>$value->id,"label"=>$value->nombre." - ".$value->getLocalidades()->nombre." - ".$value->getLocalidades()->getCiudades()->nombre,"value"=>$value->nombre);

                if($participante->barrio_residencia == $value->id ){
                  $participante->barrio_residencia = array("id"=>$value->id,"label"=>$value->nombre." - ".$value->getLocalidades()->nombre." - ".$value->getLocalidades()->getCiudades()->nombre,"value"=>$value->nombre);
                }

            }
            $array["barrio"]= $array_barrios;
            $tabla_maestra= Tablasmaestras::find("active=true AND nombre='estrato'");
            $array["estrato"] = explode(",", $tabla_maestra[0]->valor);

            //Creo todos los array del registro
            $array["participante"] = $participante;

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


//Busca el registro
$app->get('/searchTipoParticipante/{tipo:[0-9]+}', function ($tipo) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));


        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);

           if( $user_current["id"]){
                  //Si existe esta definida la variable id en el token_actual, consulto el registro

                  //consulto si el usuario que ya tiene el perfil de jurado
                  $usuario_perfil  = Usuariosperfiles::findFirst(
                    [
                      " usuario = ".$user_current["id"]." AND perfil =17"
                    ]
                  );

                  if( $usuario_perfil->id ){
                     $participante = Participantes::findFirst(
                        [
                          " usuario_perfil = ".$usuario_perfil->id." AND tipo=".$tipo
                        ]
                      );

                      if( $participante->count() > 0){
                          return json_encode($participante);
                      }else{
                          return json_encode(new Participantes );
                      }

                  }


            }else{
              return "error";
            }

        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        echo "error_metodo"+$ex->getMessage();
    }
}
);


// Crear registro participante
$app->post('/new_participante', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //$chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

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

            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                $post = $app->request->getPost();

                $user_current = json_decode($token_actual->user_current, true);

                //cunsulta si el usuario tiene datos de participante, validando el numero de documento
                //el tipo de documento y el rol de jurado.
                $old_participante = Participantes::findFirst($request->get('idp'));
                              // Si hay mayor o igual a 1 registro, procede a validar
                // en caso contrario crea nuevos registros

                if ( $old_participante != null ){

                  $new_participante = new Participantes();

                  $new_participante->creado_por = $user_current["id"];
                  $new_participante->fecha_creacion = date("Y-m-d H:i:s");
                  $new_participante->active = true;
                  $new_participante->usuario_perfil = $usuario_perfil->id;
                  $new_participante->participante_padre = $old_participante->id;
                  $new_participante->usuario_perfil = $old_participante->usuario_perfil;

                  if ($new_participante->save($post) === false) {
                      echo "error";


                      foreach ($participante->getMessages() as $message) {
                           echo $message;
                         }

                  } else {

                      $old_participante->active = false;
                      $old_participante->save();

                      echo $new_participante->id;

                    }
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

try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage();
}
?>
