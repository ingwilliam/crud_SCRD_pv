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

//Busca el registro información básica
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

           if( $user_current["id"]){

                 // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                     $usuario_perfil  = Usuariosperfiles::findFirst(
                       [
                         " usuario = ".$user_current["id"]." AND perfil =17"
                       ]
                     );

                     if( $usuario_perfil->id != null ){

                       /*
                       *Si el usuario que inicio sesion tiene perfil de jurado, asi mismo registro de  participante
                       * y  tiene asociada una convocatoria "idc"
                       */
                       $participante = Participantes::query()
                         ->join("Usuariosperfiles","Participantes.usuario_perfil = Usuariosperfiles.id")
                         ->join("Propuestas"," Participantes.id = Propuestas.participante")
                          //perfil = 17  perfil de jurado
                         ->where("Usuariosperfiles.perfil = 17 ")
                         ->andWhere("Usuariosperfiles.usuario = ".$user_current["id"])
                         ->andWhere("Propuestas.convocatoria = ".$request->get('idc'))
                         ->execute()
                         ->getFirst();


                       if( $participante->id == null ){

                         //busca la información del ultimo perfil creado
                         $old_participante = Participantes::findFirst(
                           [
                             "usuario_perfil = ".$usuario_perfil->id,
                             "order" => "id DESC" //trae el último
                           ]
                          );

                         $new_participante = clone $old_participante;
                         $new_participante->id = null;
                         $new_participante->actualizado_por = null;
                         $new_participante->fecha_actualizacion = null;
                         $new_participante->creado_por = $user_current["id"];
                         $new_participante->fecha_creacion = date("Y-m-d H:i:s");
                         $new_participante->participante_padre = $old_participante->id;
                         $new_participante->tipo = "Participante";

                         $propuesta = new Propuestas();
                         $propuesta->convocatoria = $request->get('idc');
                         $propuesta->creado_por = $user_current["id"];
                         $propuesta->fecha_creacion = date("Y-m-d H:i:s");
                         //Estado	Registrada
                         $propuesta->estado = 7;

                         $new_participante->propuestas = $propuesta;

                         if ($new_participante->save() === false) {

                           echo "error";

                           //Para auditoria en versión de pruebas
                           /*
                           foreach ($participante->getMessages() as $message) {
                                   echo $message;
                                 }
                           */

                         }else{
                            //Asigno el nuevo participante al array
                            $array["participante"] = $new_participante;
                          }

                      }else{

                        //Asigno el participante al array
                        $array["participante"] = $participante;
                      }


                         //Creo los array de los select del formulario
                         $array["categoria"]= $participante->propuestas->modalidad_participa;
                         $array["tipo_documento"]= Tiposdocumentos::find("active=true");
                         $array["sexo"]= Sexos::find("active=true");
                         $array["orientacion_sexual"]= Orientacionessexuales::find("active=true");
                         $array["identidad_genero"]= Identidadesgeneros::find("active=true");
                         $array["grupo_etnico"]= Gruposetnicos::find("active=true");
                         $array_ciudades=array();

                         //Ciudades
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

                         //Barrios
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

                         //Retorno el array
                        return json_encode( $array );

                     }else{

                       return json_encode( new Participantes() );
                     }



            }


        } else {
            echo "error_token";
        }


    } catch (Exception $ex) {

      //  echo "error_metodo";

      //Para auditoria en versión de pruebas
      echo "error_metodo". $ex->getMessage().json_encode($ex->getTrace());
    }
});


// Edito registro participante
$app->post('/edit_participante', function () use ($app, $config) {
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


                  //(return $request->get('idp');
                $participante = Participantes::findFirst($request->get('idp'));



                if( $participante->tipo == 'Participante'){

                  //valido si existe una propuesta del participante y convocatoria con el estado 9 (registrada)
                  $propuesta = Propuestas::findFirst([
                    " participante = ".$participante->id." AND convocatoria = ".$request->get('idc')
                  ]);

                  //return json_encode(  $participante->propuestas );

                  //valido si la propuesta tiene el estado registrada

                  if( $propuesta != null and $propuesta->estado == 7 ){

                      $propuesta->modalidad_participa = $request->get('categoria');
                      //return json_encode($post);
                      $participante->actualizado_por = $user_current["id"];
                      $participante->fecha_actualizacion = date("Y-m-d H:i:s");
                      $participante->propuestas = $propuesta;

                    if ($participante->save($post) === false) {
                        echo "error";

                        //Para auditoria en versión de pruebas
                        foreach ($participante->getMessages() as $message) {
                             echo $message;
                           }

                    }
                        echo $participante->id;
                  }else{
                    echo "deshabilitado";
                  }

                }



            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {

        echo "error_metodo";

        //Para auditoria en versión de pruebas
        //echo "error_metodo" . $ex->getMessage();

    }
}
);


//Busca el registro educación formal
$app->get('/search_educacion_formal', function () use ($app, $config) {
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

                 // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                     $usuario_perfil  = Usuariosperfiles::findFirst(
                       [
                         " usuario = ".$user_current["id"]." AND perfil =17"
                       ]
                     );

                     if( $usuario_perfil->id != null ){

                       //cargar los datos del registro
                      // echo "-->>>>".$request->get('idregistro');

                       if( $request->get('idregistro') ){

                             $educacionformal=Educacionformal::findFirst( $request->get('idregistro') );

                       }


                        $array["usuario_perfil"]=$usuario_perfil->id;

                         //Ciudades
                         foreach( Ciudades::find("active=true") as $value )
                         {
                             $array_ciudades[]=array("id"=>$value->id,"label"=>$value->nombre." - ".$value->getDepartamentos()->nombre." - ".$value->getDepartamentos()->getPaises()->nombre,"value"=>$value->nombre);

                             if($educacionformal->ciudad != null && $educacionformal->ciudad == $value->id ){
                                $array["ciudad_name"]=$value->nombre;
                             }

                         }
                         $array["ciudad"]=$array_ciudades;

                         $array["nivel_educacion"] = Niveleseducativos::find("active=true");
                         $array["area_conocimiento"] = Areasconocimientos::find("active=true");

                         $array["educacionformal"] = $educacionformal;

                         //Retorno el array
                        return json_encode( $array );



                     }else{
                       return json_encode( array() );
                     }

            }


        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {

        echo "error_metodo";

      //Para auditoria en versión de pruebas
      //echo "error_metodo" . $ex->getMessage();
    }
}
);


//Busca los registros de educacion formal
$app->get('/all_educacion_formal', function () use ($app, $config) {
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
             $response = array();
           if( $user_current["id"]){

                 // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                     $usuario_perfil  = Usuariosperfiles::findFirst(
                       [
                         " usuario = ".$user_current["id"]." AND perfil =17"
                       ]
                     );

                    // return json_encode($usuario_perfil);
                     if( $usuario_perfil->id != null ){



                      $participante = Participantes::query()
                        ->join("Usuariosperfiles","Participantes.usuario_perfil = Usuariosperfiles.id")
                        ->join("Propuestas"," Participantes.id = Propuestas.participante")
                         //perfil = 17  perfil de jurado
                        ->where("Usuariosperfiles.perfil = 17 ")
                        ->andWhere("Usuariosperfiles.usuario = ".$user_current["id"])
                        ->andWhere("Propuestas.convocatoria = ".$request->get('idc'))
                        ->execute()
                        ->getFirst();


                       $educacionformales = Educacionformal::find(
                         [
                           "usuario_perfil= ".$usuario_perfil->id
                           ." AND titulo LIKE '%".$request->get("search")['value']."%'"
                           ." OR institucion LIKE '%".$request->get("search")['value']."%'"
                           ." AND propuesta = ".$participante->propuestas->id,
                           "order" => 'id ASC',
                           "limit" =>  $request->get('length'),
                           "offset" =>  $request->get('start'),
                         ]
                       );

                       foreach ($educacionformales as $educacionformal) {
                         $educacionformal->creado_por = null;
                         $educacionformal->actualizado_por = null;
                         array_push($response,$educacionformal);
                       }

                       //resultado sin filtro
                       $teducacionformal = Educacionformal::find([
                         "usuario_perfil = ".$usuario_perfil->id
                       ]);

                     }

            }


            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($teducacionformal->count()),
                "recordsFiltered" => intval($teducacionformal->count()),
                "data" => $response   // total data array
            );
            //retorno el array en json
           echo json_encode($json_data);

        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {

      //  echo "error_metodo";

      //Para auditoria en versión de pruebas
      echo "error_metodo" . $ex->getMessage().$ex->getTraceAsString ();
    }
}
);


/*Retorna información de id y nombre del nucleobasico associado  al area_conocimiento*/
$app->get('/select_nucleobasico', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $categorias=  array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual != false ) {

            //Si existe consulto la convocatoria
            if($request->get('id'))
            {

                $rs = Nucleosbasicos::find(
                  [
                    "area_conocimiento = ".$request->get('id')
                  ]
                );

                //Se construye un array con la información de id y nombre de cada convocatoria para establece rel componente select
              foreach ( $rs as $key => $value) {
                      $nucleosbasicos[$key]= array("id"=>$value->id, "nombre"=>$value->nombre);
                }


            }

            echo json_encode($nucleosbasicos);
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        echo "error_metodo".$ex->getMessage();
    }
}
);

// Crea el registro
$app->post('/new_educacion_formal', function () use ($app, $config) {
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
                $post = $app->request->getPost();

                $user_current = json_decode($token_actual->user_current, true);

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                    $usuario_perfil  = Usuariosperfiles::findFirst(
                      [
                        " usuario = ".$user_current["id"]." AND perfil =17"
                      ]
                    );


                     if( $usuario_perfil->id != null ){

                       $participante = Participantes::query()
                         ->join("Usuariosperfiles","Participantes.usuario_perfil = Usuariosperfiles.id")
                         ->join("Propuestas"," Participantes.id = Propuestas.participante")
                          //perfil = 17  perfil de jurado
                         ->where("Usuariosperfiles.perfil = 17 ")
                         ->andWhere("Usuariosperfiles.usuario = ".$user_current["id"])
                         ->andWhere("Propuestas.convocatoria = ".$request->get('idc'))
                         ->execute()
                         ->getFirst();

                         $educacionformal = new Educacionformal();
                         $educacionformal->creado_por = $user_current["id"];
                         $educacionformal->fecha_creacion = date("Y-m-d H:i:s");
                         $educacionformal->active = true;
                         //al asignarle un objeto genera error, por tal motivo se envia solo el id
                         $educacionformal->propuesta = $participante->propuestas->id;
                         $educacionformal->usuario_perfil = $participante->usuario_perfil;

                         $post["id"]= null;

                        echo "educacionformal---->>".json_encode($educacionformal);
                          echo "post---->>".json_encode($post);
                         if ($educacionformal->save($post) === false) {
                                  //  return json_encode($user_current);

                             //Para auditoria en versión de pruebas
                             foreach ($participante->getMessages() as $message) {
                                  echo $message;
                                }

                         } else {
                             echo $educacionformal->id;
                         }



                     }



            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        echo "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
    }
}
);

// Edita el registro
$app->put('/edit_educacion_formal/{id:[0-9]+}', function ($id) use ($app, $config) {

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
                $post = $app->request->getPut();

                $user_current = json_decode($token_actual->user_current, true);

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                    $usuario_perfil  = Usuariosperfiles::findFirst(
                      [
                        " usuario = ".$user_current["id"]." AND perfil =17"
                      ]
                    );


                     if( $usuario_perfil->id != null ){


                       $propuesta = Propuestas::findFirst([
                         "convocatoria = ".$request->getPut('idc')
                       ]);

                         //valido si la propuesta tiene el estado registrada
                       if( $propuesta != null and $propuesta->estado == 7 ){

                           $educacionformal = Educacionformal::findFirst($id);
                           $educacionformal->actualizado_por = $user_current["id"];
                           $educacionformal->fecha_actualizacion = date("Y-m-d H:i:s");

                        //  echo "educacionformal---->>".json_encode($educacionformal);
                            //echo "post---->>".json_encode($post);
                           if ($educacionformal->save($post) === false) {
                                    //  return json_encode($user_current);

                               //Para auditoria en versión de pruebas
                               foreach ($participante->getMessages() as $message) {
                                    echo $message;
                                  }

                           } else {
                               return $educacionformal->id;
                           }
                       }else{
                         echo "deshabilitado";
                       }

                     } else {
                           return "error";
                       }

            } else {
                return "acceso_denegado";
            }
        } else {
            return "error";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
    }

}
);

// Eliminar registro de los perfiles de las convocatorias
$app->delete('/delete_educacion_formal/{id:[0-9]+}', function ($id) use ($app, $config) {
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

              //Consulto el usuario actual
              $post = $app->request->getPut();

              $user_current = json_decode($token_actual->user_current, true);

              // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                  $usuario_perfil  = Usuariosperfiles::findFirst(
                    [
                      " usuario = ".$user_current["id"]." AND perfil =17"
                    ]
                  );


                  if( $usuario_perfil->id != null ){
                    $propuesta = Propuestas::findFirst([
                      "convocatoria = ".$request->getPut('idc')
                    ]);

                      //valido si la propuesta tiene el estado registrada
                    if( $propuesta != null and $propuesta->estado == 7 ){

                      $educacionformal = Educacionformal::findFirst($id);

                      if($educacionformal->active==true){
                          $educacionformal->active=false;
                          $retorna="No";
                      }else{
                          $educacionformal->active=true;
                          $retorna="Si";
                      }

                      $educacionformal->actualizado_por = $user_current["id"];
                      $educacionformal->fecha_actualizacion = date("Y-m-d H:i:s");

                      if ($educacionformal->save($post) === false) {
                        //Para auditoria en versión de pruebas
                        foreach ($participante->getMessages() as $message) {
                          echo $message;
                          }

                      }else {
                        return $retorna;
                      }

                  }else{
                        echo "deshabilitado";
                  }

                }else {
                    return "error";
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


try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage().$e->getTraceAsString ();
}
?>
