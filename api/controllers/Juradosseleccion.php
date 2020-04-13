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
use Phalcon\Db\RawValue;

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
        "host" => $config->database->host,"port" => $config->database->port,
        "username" => $config->database->username,
        "password" => $config->database->password,
        "dbname" => $config->database->name
            )
    );
});

$app = new Micro($di);

//inicializa el formulario
$app->get('/init', function () use ($app, $config) {
    try {

      //Instancio los objetos que se van a manejar
      $request = new Request();
      $tokens = new Tokens();
      $array=array();

      //Consulto si al menos hay un token
      $token_actual = $tokens->verificar_token($request->get('token'));

      //Si el token existe y esta activo entra a realizar la tabla
      if ($token_actual > 0) {

        //se establecen los valores del usuario
        $user_current = json_decode($token_actual->user_current, true);

        if( $user_current["id"]){

            //Datos select entidades
           $array["entidades"] = Entidades::find("active = true");

           //Datos select año
           for($i = date("Y"); $i >= 2016; $i--){
               $array["anios"][] = $i;
           }

           //Datos select tipos jurados
           //busca los valores de tipos de jurados registrados en tablas maestras
           $tipos_jurado  =  Tablasmaestras::findFirst(
               [
                 "nombre ='tipos_jurado' "
                 ." AND active = true"
                ]
             );

           $array["tipos_jurado"] = array();

           foreach ( explode(",", $tipos_jurado->valor)  as  $tipo) {
             array_push( $array["tipos_jurado"], ["id"=> $tipo, "nombre"=> $tipo]);
           }

          //Retorno el array
         return json_encode( $array );

        }

      } else {
          return "error_token";
      }


    } catch (Exception $ex) {

      //return "error_metodo";
      //Para auditoria en versión de pruebas
      return "error_metodo: ". $ex->getMessage().json_encode($ex->getTrace());
    }

});

//Retorna información de id y nombre de las convocatorias
$app->get('/select_convocatorias', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $convocatorias =  array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual != false ) {

            //Si existe consulto la convocatoria
            if( $request->get('entidad') && $request->get('anio') )
            {

               $rs= Convocatorias::find(
                  [
                      " entidad = ".$request->get('entidad')
                      ." AND anio = ".$request->get('anio')
                      ." AND estado = 5 "
                      ." AND modalidad != 2 " //2	Jurados
                      ." AND active = true "
                      ." AND convocatoria_padre_categoria is NULL"
                  ]
                );

                //Se construye un array con la información de id y nombre de cada convocatoria para establece rel componente select
              //foreach ( $rs as $key => $value) {
                //      $nucleosbasicos[$key]= array("id"=>$value->id, "nombre"=>$value->nombre);
                //}

                foreach ( $rs as $convocatoria) {
                  array_push($convocatorias, ["id"=> $convocatoria->id, "nombre"=> $convocatoria->nombre ] );
                }


            }

            return json_encode($convocatorias);
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        return "error_metodo".$ex->getMessage();
    }
}
);

//Retorna información de id y nombre de las categorias de la convocatoria
$app->get('/select_categorias', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $response =  array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual != false ) {

            //Si existe consulto la convocatoria
            if( $request->get('convocatoria') )
            {

              $convocatoria =  Convocatorias::findFirst($request->get('convocatoria'));

              if( $convocatoria->tiene_categorias){
                $categorias = Convocatorias::find(
                   [
                       " convocatoria_padre_categoria = ".$convocatoria->id
                       ." AND active = true "
                   ]
                 );

              }


                //Se construye un array con la información de id y nombre de cada convocatoria para establece rel componente select
              //foreach ( $rs as $key => $value) {
                //      $nucleosbasicos[$key]= array("id"=>$value->id, "nombre"=>$value->nombre);
                //}

                foreach ( $categorias as $categoria) {
                  array_push($response, ["id"=> $categoria->id, "nombre"=> $categoria->nombre ] );
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

//Retorna información de los jurados preseleccionados (postulados+banco)
$app->get('/all_seleccionados', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
      //  $juradospostulados =  array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual != false ) {

          //se establecen los valores del usuario
          $user_current = json_decode($token_actual->user_current, true);
          $response = array();

           if( $user_current["id"]){

             $total_evaluacion = Tablasmaestras::findFirst([" nombre = 'puntaje_minimo_jurado_seleccionar' "]);

             //busca los que se postularon
             if( $request->get('convocatoria')){

               $convocatoria =  Convocatorias::findFirst($request->get('convocatoria'));

               //la convocatoria tiene categorias y son diferentes?, caso 3
               if( $convocatoria->tiene_categorias && $convocatoria->diferentes_categorias && $request->get('categoria') ){

                 $juradospostulados = Juradospostulados::find(
                   [
                     " convocatoria = ".$request->get('categoria')
                    ." AND total_evaluacion >= ".$total_evaluacion->valor
                   ]
                 );

               }elseif($convocatoria->tiene_categorias && !$convocatoria->diferentes_categorias && $request->get('categoria')) {
                 $juradospostulados = Juradospostulados::find(
                   [
                     " convocatoria = ".$request->get('categoria')
                    ." AND total_evaluacion >= ".$total_evaluacion->valor
                   ]
                 );
               } elseif($convocatoria->tiene_categorias && !$convocatoria->diferentes_categorias && !$request->get('categoria')) {
                  $juradospostulados = Juradospostulados::find(
                    [
                      " convocatoria = ".$request->get('convocatoria')
                     ." AND total_evaluacion >= ".$total_evaluacion->valor
                    ]
                  );
                }else{
                  $juradospostulados = Juradospostulados::find(
                    [
                      " convocatoria = ".$request->get('convocatoria')
                     ." AND total_evaluacion >= ".$total_evaluacion->valor
                    ]
                  );
                }


               if( $juradospostulados->count() > 0 ){

                 foreach ($juradospostulados as $juradopostulado) {

                   $notificacion_activa = Juradosnotificaciones::findFirst(
                       [
                         " active = true"
                         ." AND juradospostulado = ".$juradopostulado->id
                       ]
                     );

                    // return json_encode($notificacion_activa->estado);

                    array_push( $response, [
                      "postulado" => ( $juradopostulado->tipo_postulacion == 'Inscrita' ? true : false ),
                      "id" =>  $juradopostulado->propuestas->participantes->id,
                      "tipo_documento" =>  $juradopostulado->propuestas->participantes->tiposdocumentos->nombre,
                      "numero_documento" =>  $juradopostulado->propuestas->participantes->numero_documento,
                      "nombres" =>  $juradopostulado->propuestas->participantes->primer_nombre." ".$juradopostulado->propuestas->participantes->segundo_nombre,
                      "apellidos" =>  $juradopostulado->propuestas->participantes->primer_apellido." ".$juradopostulado->propuestas->participantes->segundo_apellido,
                      "id_postulacion"=>$juradopostulado->id,
                      "puntaje" =>  $juradopostulado->total_evaluacion,
                      "aplica_perfil" =>  $juradopostulado->aplica_perfil,
                      "estado_postulacion" =>  $juradopostulado->estado,
                      "codigo_propuesta" =>  $juradopostulado->propuestas->codigo,
                      "estado_notificacion" =>  ( $notificacion_activa->estado == null? null : Estados::findFirst( $notificacion_activa->estado )->nombre ),
                      "notificacion"=> $notificacion_activa->key
                      ] );
                 }

               }
             }
           }

           //return json_encode($juradospostulados);

          //creo el array
          $json_data = array(
              "draw" => intval($request->get("draw")),
              "recordsTotal" => intval( count($response) ),
              "recordsFiltered" => intval( count($response) ),
              "data" => $response   // total data array
          );
          //retorno el array en json
         return json_encode($json_data);


        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        return "error_metodo".$ex->getMessage();
    }
}
);


//Notifica al jurado preseleccionado
$app->put('/notificar', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //$chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            $user_current = json_decode($token_actual->user_current, true);

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPut('modulo') . "&token=" . $request->getPut('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

              $postulacion = Juradospostulados::findFirst($request->getPut('postulacion'));

              if($postulacion){

                // Start a transaction
                $this->db->begin();

                $notificaciones = array() ;

              //  return json_encode($postulacion->Notificaciones);

                foreach (  $postulacion->Notificaciones as $key => $notificacion) {
                  $notificacion->active = false;
                  array_push($notificaciones,$notificacion);
                }

                 $postulacion->Notificaciones = $notificaciones;

                 if ( $postulacion->save() === false ) {

                     $this->db->rollback();
                   //Para auditoria en versión de pruebas
                   foreach ($postulacion->getMessages() as $message) {
                        echo $message;
                      }

                   return "error";
                 }

                $jurado_notificacion = new Juradosnotificaciones();
                $jurado_notificacion->juradospostulado = $postulacion->id;
                $jurado_notificacion->fecha_creacion =  date("Y-m-d H:i:s");
                $jurado_notificacion->creado_por = $user_current["id"];
                $jurado_notificacion->active = true;
                $jurado_notificacion->estado = 14; //14	jurado_notificaciones	Notificada
                $jurado_notificacion->key = $this->security->hash("123456");
                $jurado_notificacion->fecha_inicio_evaluacion = $request->getPut('fecha_inicio_evaluacion');
                $jurado_notificacion->fecha_fin_evaluacion = $request->getPut('fecha_fin_evaluacion');
                $jurado_notificacion->fecha_deliberacion = $request->getPut('fecha_deliberacion');
                $jurado_notificacion->valor_estimulo = $request->getPut('valor_estimulo');

                // The model failed to save, so rollback the transaction
                if ( $jurado_notificacion->save() === false ) {

                    $this->db->rollback();
                  //Para auditoria en versión de pruebas
                  foreach ($jurado_notificacion->getMessages() as $message) {
                       echo $message;
                     }

                  return "error";
                }else {

                    $participante = $postulacion->Propuestas->Participantes;
                    //echo json_encode($user_current);
                    //Creo el cuerpo del messaje html del email
                    $html_solicitud_usuario= Tablasmaestras::find("active=true AND nombre='html_jurado_notificacion_preseleccion'")[0]->valor;

                    $html_solicitud_usuario= str_replace("**fecha_creacion**", date("d/m/Y"), $html_solicitud_usuario);
                    $html_solicitud_usuario= str_replace("**nombre_jurado**",$participante->primer_nombre." ".$participante->primer_apellido , $html_solicitud_usuario);
                    $html_solicitud_usuario= str_replace("**correo_jurado**",$participante->correo_electronico, $html_solicitud_usuario);
                    $html_solicitud_usuario= str_replace("**ciudad_residencia_jurado**",Ciudades::findFirst($participante->ciudad_residencia)->nombre, $html_solicitud_usuario);
                    $html_solicitud_usuario= str_replace("**jurado_rol**",($request->getPut('option_suplente') ? "Principal":"Suplente"), $html_solicitud_usuario);
                    $html_solicitud_usuario= str_replace("**anio**",$postulacion->Propuestas->Convocatorias->anio, $html_solicitud_usuario);
                    $html_solicitud_usuario= str_replace("**nombre_convocatoria**",$postulacion->Convocatorias->nombre, $html_solicitud_usuario);
                    //Total de propuestas que estan habilitadas para evaluar
                    $tot_propuestas = count(Propuestas::find(" convocatoria = ".$postulacion->Convocatorias->id." AND estado = 24 "));
                    $html_solicitud_usuario= str_replace("**total_propuestas**",$tot_propuestas , $html_solicitud_usuario);

                    $html_solicitud_usuario= str_replace("**fecha_inicio_evaluacion**",$request->getPut('fecha_inicio_evaluacion'), $html_solicitud_usuario);
                    $html_solicitud_usuario= str_replace("**fecha_fin_evaluacion**",$request->getPut('fecha_fin_evaluacion'), $html_solicitud_usuario);
                    $html_solicitud_usuario= str_replace("**fecha_deliberacion**",$request->getPut('fecha_deliberacion'), $html_solicitud_usuario);
                    $html_solicitud_usuario= str_replace("**valor_estimulo**",$request->getPut('valor_estimulo'), $html_solicitud_usuario);

                    $html_solicitud_usuario= str_replace("**nombre_funcionario**", $user_current["primer_nombre"]." ".$user_current["segundo_nombre"]." ".$user_current["primer_apellido"]." ".$user_current["segundo_apellido"] , $html_solicitud_usuario);
                    $html_solicitud_usuario= str_replace("**correo_funcionario**", $user_current["username"], $html_solicitud_usuario);

                    $html_solicitud_usuario= str_replace("**enlace_aceptar**", $config->sistema->url_admin."pages/jurados/notificacion.html?key=".$jurado_notificacion->key."&opc=a", $html_solicitud_usuario);
                    $html_solicitud_usuario= str_replace("**enlace_rechazar**", $config->sistema->url_admin."pages/jurados/notificacion.html?key=".$jurado_notificacion->key."&opc=r", $html_solicitud_usuario);

                    //servidor smtp ambiente de prueba
                    /*$mail = new PHPMailer();
                    $mail->IsSMTP();
                    $mail->SMTPAuth = true;
                    $mail->Host = "smtp.gmail.com";
                    $mail->SMTPSecure = 'ssl';
                    $mail->Username = "cesar.augusto.britto@gmail.com";
                    $mail->Password = "Guarracuco2016";
                    $mail->Port = 465;//25 o 587 (algunos alojamientos web bloquean el puerto 25)
                    $mail->CharSet = "UTF-8";
                    $mail->IsHTML(true); // El correo se env  a como HTML
                    $mail->From = "convocatorias@scrd.gov.co";
                    //$mail->From = "cesar.augusto.britto@gmail.com";
                    $mail->FromName = "Sistema de Convocatorias";
                    $mail->AddAddress($participante->correo_electronico);//direccion de correo del jurado participante
                    //$mail->AddAddress("cesar.augusto.britto@gmail.com");//direccion de prueba
                    //$mail->AddBCC($user_current["username"]); //con copia al misional que realiza la invitación
                    //$mail->AddBCC("cesar.augusto.britto@gmail.com");//direccion de prueba
                    $mail->Subject = "Sistema de Convocatorias - Invitación designación de jurado";
                    $mail->Body = $html_solicitud_usuario;
                    */

                  /*Servidor SMTP producción*/
                    $mail = new PHPMailer();
                    $mail->IsSMTP();
                    $mail->Host = "smtp-relay.gmail.com";
                    $mail->Port = 25;
                    $mail->CharSet = "UTF-8";
                    $mail->IsHTML(true); // El correo se env  a como HTML
                    $mail->From = "convocatorias@scrd.gov.co";
                    $mail->FromName = "Sistema de Convocatorias";
                    $mail->AddAddress($participante->correo_electronico);
                    $mail->AddBCC($user_current["username"]); //con copia al misional que realiza la invitación
                    $mail->Subject = "Sistema de Convocatorias - Invitación designación de jurado";
                    $mail->Body = $html_solicitud_usuario;

                    // Envia el correo.
                    if ( $mail->Send() ) {

                        // Commit the transaction
                        $postulacion->rol = ($request->getPut('option_suplente') ? "Principal":"Suplente");
                        $postulacion->fecha_actualizacion =  date("Y-m-d H:i:s");
                        $postulacion->actualizado_por = $user_current["id"];

                        if ( $postulacion->save() === false ) {
                          //Para auditoria en versión de pruebas
                          $this->db->rollback();
                          foreach ($postulacion->getMessages() as $message) {
                               echo $message;
                             }

                          return "error";
                        }

                        $this->db->commit();
                        echo "exito";
                    } else {
                        echo "error_email";
                        $this->db->rollback();
                         echo 'Mailer Error: ' . $mail->ErrorInfo;
                    }
                }

              }else{
                  return "error";
              }

            } else {
                return "acceso_denegado";
            }

        } else {
            return "error_token";
        }

    } catch (Exception $ex) {
        //return "error_metodo";
        //Para auditoria en versión de pruebas
        return "error_metodo" .  $ex->getMessage().json_encode($ex->getTrace());
    }
});

//Retorna la información del usuario notificado
$app->get('/notificado_key_notificacion', function () use ($app) {
    try {
      //Instancio los objetos que se van a manejar
      $request = new Request();

        $notificacion = Juradosnotificaciones::findFirst(
          [
            " key = '".$request->get('key')."'"
          ]
        );

        if ($notificacion && $notificacion->active) {

            $participante = $notificacion->Juradospostulados->Propuestas->Participantes;
            $convocatoria = $notificacion->Juradospostulados->Propuestas->Convocatorias;

            return json_encode(
              [ "participante"=>[
                  "participante"=> $participante->id,
                  "usuario"=> $participante->Usuariosperfiles->usuario,
                  "primer_nombre"=> $participante->primer_nombre,
                  "segundo_nombre"=> $participante->segundo_nombre,
                  "primer_apellido"=> $participante->primer_apellido,
                  "segundo_apellido"=> $participante->segundo_apellido,
                  "tipo_documento"=> $participante->Tiposdocumentos->nombre,
                  "numero_documento"=> $participante->numero_documento,
                  ],
                "notificacion"=>[
                  "convocatoria_banco"=>$convocatoria->nombre,
                  "vigencia"=>$convocatoria->anio,
                  "estado"=>Estados::findFirst($notificacion->estado)->nombre
                  ]
              ]
            );

        } else {
            return "error";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        return "error_metodo".$ex->getMessage();
    }
}
);

//Cambia el estado de la notificacion a aceptada
$app->put('/aceptar_notificacion', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();

        $notificacion = Juradosnotificaciones::findFirst(
          [
            " key = '".$request->getPut('key')."'"
          ]
        );

        //Si la notificacion existe y esta activa
        //14	jurado_notificaciones	Notificada
        if ($notificacion && $notificacion->active && $notificacion->estado== 14 ) {

          $notificacion->estado = 15; //15	jurado_notificaciones	Aceptada
          $notificacion->fecha_actualizacion = date("Y-m-d H:i:s");
          $notificacion->fecha_aceptacion = date("Y-m-d H:i:s");

          if ( $notificacion->save() === false ) {
            //Para auditoria en versión de pruebas
            foreach ($notificacion->getMessages() as $message) {
                 echo $message;
               }

            return "error";
          }else{
            return "exito";
          }

        }else{
            return "error";
        }



    } catch (Exception $ex) {
        //return "error_metodo";
        //Para auditoria en versión de pruebas
        return "error_metodo" .  $ex->getMessage().json_encode($ex->getTrace());
    }
});

//Cambia el estado de la notificacion a rechazada
$app->put('/rechazar_notificacion', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();

        $notificacion = Juradosnotificaciones::findFirst(
          [
            " key = '".$request->getPut('key')."'"
          ]
        );

        //Si la notificacion existe y esta activa
        //14	jurado_notificaciones	Notificada
        if ($notificacion && $notificacion->active && $notificacion->estado== 14 ) {

          $notificacion->estado = 16; //16	jurado_notificaciones	Rechazada
          $notificacion->fecha_actualizacion = date("Y-m-d H:i:s");
          $notificacion->fecha_rechazo = date("Y-m-d H:i:s");
        //  $notificacion->actualizado_por = $request->put('usuario');

          if ( $notificacion->save() === false ) {
            //Para auditoria en versión de pruebas
            foreach ($notificacion->getMessages() as $message) {
                 echo $message;
               }

            return "error";
          }

        }else  if ($notificacion && $notificacion->active && $notificacion->estado== 16 ) {
            return "rechazada";
        }else{
            return "deshabilitado";
        }


    } catch (Exception $ex) {
        //return "error_metodo";
        //Para auditoria en versión de pruebas
        return "error_metodo" .  $ex->getMessage().json_encode($ex->getTrace());
    }
});


//Notifica al jurado preseleccionado
$app->put('/declinar', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //$chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            $user_current = json_decode($token_actual->user_current, true);

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPut('modulo') . "&token=" . $request->getPut('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

              $postulacion = Juradospostulados::findFirst($request->getPut('postulacion'));

              $notificacion = Juradosnotificaciones::findFirst(
                [
                  " key = '". $request->getPut('key')."'"
                ]
              );

              $notificacion->estado = 17; //17	jurado_notificaciones	Declinada
              $notificacion->fecha_actualizacion = date("Y-m-d H:i:s");
              $notificacion->actualizado_por =  $user_current["id"];;

              if ( $notificacion->save() === false ) {
                //Para auditoria en versión de pruebas
                foreach ($notificacion->getMessages() as $message) {
                     echo $message;
                   }

                return "error";
              }

            } else {
                return "acceso_denegado";
            }

        } else {
            return "error_token";
        }

    } catch (Exception $ex) {
        //return "error_metodo";
        //Para auditoria en versión de pruebas
        return "error_metodo" .  $ex->getMessage().json_encode($ex->getTrace());
    }
});

//Retorna información de la notificación
$app->get('/notificacion', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $convocatorias =  array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual != false ) {

            //Si existe consulto la convocatoria
            if( $request->get('key') ) {

                $notificacion =  Juradosnotificaciones::findFirst(
                  [
                    " key = '".$request->get('key')."'"
                  ]);

                  $usario_notifico= Usuarios::findFirst($notificacion->creado_por);

                  return json_encode( [
                    "id_notificacion"=> $notificacion->id,
                    "usuario"=> $usario_notifico->primer_nombre." ".$usario_notifico->segundo_nombre." ".$usario_notifico->primer_apellido." ".$usario_notifico->segundo_apellido,
                    "fecha_creacion"=>$notificacion->fecha_creacion,
                    "estado"=>Estados::findFirst($notificacion->estado)->nombre,
                    "tipo_jurado"=>$notificacion->Juradospostulados->tipo_postulacion,
                    "rol_jurado"=>$notificacion->Juradospostulados->rol,
                    "fecha_aceptacion"=>$notificacion->fecha_aceptacion,
                    "fecha_rechazo"=>$notificacion->fecha_rechazo,
                    "valor_estimulo"=>$notificacion->valor_estimulo

                  ] );

                  return json_encode($notificacion);
            }else{
                return "error";
            }


        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        return "error_metodo".$ex->getMessage();
    }
}
);

//Retorna información de los jurados preseleccionados (postulados+banco)
$app->get('/all_grupos_evaluacion', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
      //  $juradospostulados =  array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual != false ) {

          //se establecen los valores del usuario
          $user_current = json_decode($token_actual->user_current, true);
          $response = array();

           if( $user_current["id"]){

             $total_evaluacion = Tablasmaestras::findFirst([" nombre = 'puntaje_minimo_jurado_seleccionar' "]);

             //busca los que se postularon
             if( $request->get('convocatoria')){

               $convocatoria =  Convocatorias::findFirst($request->get('convocatoria'));

               $gruposevaluadores = Gruposevaluadores::query()
                     ->join("Convocatoriasrondas","Gruposevaluadores.ronda = Convocatoriasrondas.id")
                     ->where("Convocatoriasrondas.convocatoria = ".$convocatoria->id)
                     ->execute();

               if( $gruposevaluadores->count() > 0 ){

                 foreach ($gruposevaluadores as $grupoevaluador) {

                    array_push( $response, [
                      "id" =>  $grupoevaluador->id,
                      "nombre_grupo" =>  $grupoevaluador->nombre,
                      "numero_principales" =>  0,
                      "numero_suplentes" => 0,
                      "numero_total" =>  0
                      ] );
                 }

               }
             }
           }

           //return json_encode($juradospostulados);

          //creo el array
          $json_data = array(
              "draw" => intval($request->get("draw")),
              "recordsTotal" => intval( count($response) ),
              "recordsFiltered" => intval( count($response) ),
              "data" => $response   // total data array
          );
          //retorno el array en json
         return json_encode($json_data);


        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        return "error_metodo".$ex->getMessage();
    }
}
);












//Busca el registro información básica del participante
$app->get('/search_convocatoria_propuesta', function () use ($app, $config) {

    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $a = array();
        $array["participantes"] = array();
        $delimiter = array("[","]","\"");

      //  $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);

           if( $user_current["id"]){

             /*

                       $participante = Participantes::query()
                         ->join("Usuariosperfiles","Participantes.usuario_perfil = Usuariosperfiles.id")
                         ->join("Propuestas"," Participantes.id = Propuestas.participante")
                          //perfil = 17  perfil de jurado
                         ->where("Usuariosperfiles.perfil = 17 ")
                         ->andWhere("Usuariosperfiles.usuario = ".$participante->usuariosperfiles->usuario )
                         ->andWhere("Propuestas.convocatoria = ".$request->get('idc'))
                         ->execute()
                         ->getFirst();

                         */
            //$participante = Participantes::findFirst($request->get('participante'));
            $convocatoria = Convocatorias::findFirst($request->get('convocatoria'));
            $array["convocatoria"] = $convocatoria;

            $postulacion = Juradospostulados::findFirst($request->get('participante'));
            $participante = $postulacion->propuestas->participantes;

            //Se establec elos valores del participante
            if( $participante->id != null ){

            //  $new_participante = clone $old_participante;

              /*$participante->tipo_documento = Tiposdocumentos::findFirst($participante->tipo_documento)->descripcion;
              $participante->sexo = Sexos::findFirst($participante->sexo)->nombre;
              $participante->ciudad_residencia = Ciudades::findFirst($participante->ciudad_residencia)->nombre;
              $participante->fecha_creacion = null;
              $participante->participante_padre = null;

              //Asigno el participante al array
              $array["participante"] = $participante;
              $array["perfil"] = $participante->propuestas->resumen;*/

              /*$array["participantes"] =  Convocatoriasparticipantes::find([
                " convocatoria = ".$request->get('idc')
                ." AND tipo_participante = 4 "
                ." AND active = true ",
                "order" => 'orden ASC',
              ]);
              */

              $participante =  Convocatoriasparticipantes::findFirst( $postulacion->perfil );

              //se modifican los valores de algunas propiedades de cada registro

                $participante->area_perfil = str_replace($delimiter, "",  $value->area_perfil );
                $participante->area_conocimiento = str_replace($delimiter, "",  $value->area_conocimiento );
                $participante->nivel_educativo = str_replace($delimiter, "",  $value->nivel_educativo );
                $participante->formacion_profesional = ($value->formacion_profesional)? "Si": "No";
                $participante->formacion_postgrado= ($value->formacion_postgrado)? "Si": "No";
                $participante->reside_bogota= ($value->reside_bogota)? "Si": "No";

               $array["participante"] = $participante;

            }else{


              if( $convocatoria->tiene_categorias && $convocatoria->diferentes_categorias  ){


               $participantes =  Convocatoriasparticipantes::find([
                  "convocatoria = ".$request->get('categoria')
                  ." AND tipo_participante = 4"
                ]);

                foreach ($participantes as $key => $value) {
                  $value->area_perfil = str_replace($delimiter, "",  $value->area_perfil );
                  $value->area_conocimiento = str_replace($delimiter, "",  $value->area_conocimiento );
                  $value->nivel_educativo = str_replace($delimiter, "",  $value->nivel_educativo );
                  $value->formacion_profesional = ($value->formacion_profesional)? "Si": "No";
                  $value->formacion_postgrado= ($value->formacion_postgrado)? "Si": "No";
                  $value->reside_bogota= ($value->reside_bogota)? "Si": "No";
                  $a[$key] = $value;
                  array_push( $array["participantes"],$value );
                }


              } else {



                $participantes =  Convocatoriasparticipantes::find([
                  " convocatoria = ".$request->get('convocatoria')
                  ." AND tipo_participante = 4"
                ]);

                foreach ($participantes as $key => $value) {
                  $value->area_perfil = str_replace($delimiter, "",  $value->area_perfil );
                  $value->area_conocimiento = str_replace($delimiter, "",  $value->area_conocimiento );
                  $value->nivel_educativo = str_replace($delimiter, "",  $value->nivel_educativo );
                  $value->formacion_profesional = ($value->formacion_profesional)? "Si": "No";
                  $value->formacion_postgrado= ($value->formacion_postgrado)? "Si": "No";
                  $value->reside_bogota= ($value->reside_bogota)? "Si": "No";
                  $a[$key] = $value;
                  array_push( $array["participantes"],$value );
                }

              }


            }

            return json_encode( $array );

           }else {
               echo "error";
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


/**Busca la información básica del jurado
* participante,
* resumen de la propuesta (perfil)
*/
$app->get('/search_info_basica_jurado', function () use ($app, $config) {

    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

      //  $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);

           if( $user_current["id"]){

             /*

                       $participante = Participantes::query()
                         ->join("Usuariosperfiles","Participantes.usuario_perfil = Usuariosperfiles.id")
                         ->join("Propuestas"," Participantes.id = Propuestas.participante")
                          //perfil = 17  perfil de jurado
                         ->where("Usuariosperfiles.perfil = 17 ")
                         ->andWhere("Usuariosperfiles.usuario = ".$participante->usuariosperfiles->usuario )
                         ->andWhere("Propuestas.convocatoria = ".$request->get('idc'))
                         ->execute()
                         ->getFirst();

                         */
          //  $participante = Participantes::findFirst($request->get('participante'));

            if( $request->get('postulacion') && $request->get('postulacion') == 'null'){

                $participante = Participantes::findFirst($request->get('participante'));

            }elseif( $request->get('postulacion') && $request->get('postulacion') != 'null'){
              $postulacion = Juradospostulados::findFirst($request->get('postulacion'));
              if($postulacion->perfil ){
                $array["postulacion_perfil"] = Convocatoriasparticipantes::findFirst( $postulacion->perfil ) ;
              }
              $participante = $postulacion->propuestas->participantes;
            }

            if( $participante->id != null ){

            //  $new_participante = clone $old_participante;

              $participante->tipo_documento = Tiposdocumentos::findFirst($participante->tipo_documento)->descripcion;
              $participante->sexo = Sexos::findFirst($participante->sexo)->nombre;
              $participante->ciudad_residencia = Ciudades::findFirst($participante->ciudad_residencia)->nombre;
              $participante->fecha_creacion = null;
              $participante->participante_padre = null;

              //Asigno el participante al array
              $array["participante"] = $participante;
              $array["propuesta_resumen"] = $participante->propuestas->resumen;

            //  $array["perfiles_covocatoria"] = Convocatoriasparticipantes::find("convocatoria = " $participante->propuestas->convocatorias->id


            }else{
                $array["participante"] =  new Participantes();
            }

        return json_encode( $array );

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

//Busca los registros de documento
$app->get('/all_documento', function () use ($app, $config) {
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
            $tdocumento = array();

           if( $user_current["id"]){

                    /*  $participante = Participantes::query()
                        ->join("Usuariosperfiles","Participantes.usuario_perfil = Usuariosperfiles.id")
                        ->join("Propuestas"," Participantes.id = Propuestas.participante")
                         //perfil = 17  perfil de jurado
                        ->where("Usuariosperfiles.perfil = 17 ")
                        ->andWhere("Usuariosperfiles.usuario = ".$user_current["id"])
                        ->andWhere("Propuestas.convocatoria = ".$request->get('idc'))
                        ->execute()
                        ->getFirst();
                        */

                      // $participante = Participantes::findFirst($request->get('participante'));
                      // $postulacion = Juradospostulados::findFirst($request->get('participante'));
                      // $participante = $postulacion->propuestas->participantes;

                       if( $request->get('postulacion') && $request->get('postulacion') == 'null'){

                           $participante = Participantes::findFirst($request->get('participante'));
                       }elseif( $request->get('postulacion') && $request->get('postulacion') != 'null'){
                         $postulacion = Juradospostulados::findFirst($request->get('postulacion'));
                         $participante = $postulacion->propuestas->participantes;
                       }


                       $documentos = Propuestajuradodocumento::find(
                         [
                           " propuesta = ".$participante->propuestas->id,
                           "order" => 'id ASC',
                           "limit" =>  $request->get('length'),
                           "offset" =>  $request->get('start'),
                         ]
                       );

                       foreach ($documentos as $documento) {

                         $tipo = Categoriajurado::findFirst(
                           ["active=true AND id=".$documento->categoria_jurado]
                         );
                         $documento->categoria_jurado = $tipo->nombre;

                         $documento->creado_por = null;
                         $documento->actualizado_por = null;
                         array_push($response,$documento);
                       }

                       //resultado sin filtro
                       $tdocumento = Propuestajuradodocumento::find([
                         " propuesta = ".$participante->propuestas->id,
                       ]);



            }

            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval( $tdocumento->count()),
                "recordsFiltered" => intval($documentos->count()),
                "data" => $response   // total data array
            );
            //retorno el array en json
           return json_encode($json_data);

        } else {
            return "error_token";
        }
    } catch (Exception $ex) {

      //  echo "error_metodo";
      //Para auditoria en versión de pruebas
      return "error_metodo" . $ex->getMessage().$ex->getTraceAsString ();
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

                    /*  $participante = Participantes::query()
                        ->join("Usuariosperfiles","Participantes.usuario_perfil = Usuariosperfiles.id")
                        ->join("Propuestas"," Participantes.id = Propuestas.participante")
                         //perfil = 17  perfil de jurado
                        ->where("Usuariosperfiles.perfil = 17 ")
                        ->andWhere("Usuariosperfiles.usuario = ".$user_current["id"])
                        ->andWhere("Propuestas.convocatoria = ".$request->get('idc'))
                        ->execute()
                        ->getFirst();

                        */

                       //$participante = Participantes::findFirst($request->get('participante'));
                       //$postulacion = Juradospostulados::findFirst($request->get('participante'));
                       //$participante = $postulacion->propuestas->participantes;

                       if( $request->get('postulacion') && $request->get('postulacion') == 'null'){

                           $participante = Participantes::findFirst($request->get('participante'));

                       }elseif( $request->get('postulacion') && $request->get('postulacion') != 'null'){

                         $postulacion = Juradospostulados::findFirst($request->get('postulacion'));
                         $participante = $postulacion->propuestas->participantes;

                       }


                       $educacionformales = Educacionformal::find(
                         [
                           " propuesta = ".$participante->propuestas->id
                           ." AND ( titulo LIKE '%".$request->get("search")['value']."%'"
                           ." OR institucion LIKE '%".$request->get("search")['value']."%')",
                           "order" => 'id ASC',
                           "limit" =>  $request->get('length'),
                           "offset" =>  $request->get('start'),
                         ]
                       );

                       foreach ($educacionformales as $educacionformal) {

                         $ciudad =  Ciudades::findFirst(
                           ["active=true AND id=".$educacionformal->ciudad]
                         );

                         $educacionformal->ciudad = $ciudad->nombre;
                         $educacionformal->creado_por = null;
                         $educacionformal->actualizado_por = null;
                         $educacionformal->numero_semestres = ( $educacionformal->numero_semestres == null)? "N/D":$educacionformal->numero_semestres;
                         array_push($response,$educacionformal);
                       }

                       //resultado sin filtro
                       $teducacionformal = Educacionformal::find(
                         [
                           " propuesta = ".$participante->propuestas->id
                         ]
                        );



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

//Busca los registros de Educacion no formal
$app->get('/all_educacion_no_formal', function () use ($app, $config) {
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


                    /*  $participante = Participantes::query()
                        ->join("Usuariosperfiles","Participantes.usuario_perfil = Usuariosperfiles.id")
                        ->join("Propuestas"," Participantes.id = Propuestas.participante")
                         //perfil = 17  perfil de jurado
                        ->where("Usuariosperfiles.perfil = 17 ")
                        ->andWhere("Usuariosperfiles.usuario = ".$user_current["id"])
                        ->andWhere("Propuestas.convocatoria = ".$request->get('idc'))
                        ->execute()
                        ->getFirst();
                        */

                       //$participante = Participantes::findFirst($request->get('participante'));
                       //$postulacion = Juradospostulados::findFirst($request->get('participante'));
                       //$participante = $postulacion->propuestas->participantes;

                       if( $request->get('postulacion') && $request->get('postulacion') == 'null'){

                           $participante = Participantes::findFirst($request->get('participante'));
                       }elseif( $request->get('postulacion') && $request->get('postulacion') != 'null'){
                         $postulacion = Juradospostulados::findFirst($request->get('postulacion'));
                         $participante = $postulacion->propuestas->participantes;
                       }


                       $educacionnoformales = Educacionnoformal::find(
                         [
                           " propuesta = ".$participante->propuestas->id
                           ." AND ( nombre LIKE '%".$request->get("search")['value']."%'"
                           ." OR institucion LIKE '%".$request->get("search")['value']."%' )",
                           "order" => 'id ASC',
                           "limit" =>  $request->get('length'),
                           "offset" =>  $request->get('start'),
                         ]
                       );

                       foreach ($educacionnoformales as $educacionnoformal) {

                         $ciudad =  Ciudades::findFirst(
                           ["active=true AND id=".$educacionnoformal->ciudad]
                         );

                         $educacionnoformal->ciudad = $ciudad->nombre;
                         $educacionnoformal->creado_por = null;
                         $educacionnoformal->actualizado_por = null;
                         array_push($response,$educacionnoformal);
                       }

                       //resultado sin filtro
                       $teducacionnoformal = Educacionnoformal::find(
                         [
                          " propuesta = ".$participante->propuestas->id
                         ]
                       );



            }


            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($teducacionnoformal->count()),
                "recordsFiltered" => intval($teducacionnoformal->count()),
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

//Busca los registros de experiencia_laboral
$app->get('/all_experiencia_laboral', function () use ($app, $config) {
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



                    /*  $participante = Participantes::query()
                        ->join("Usuariosperfiles","Participantes.usuario_perfil = Usuariosperfiles.id")
                        ->join("Propuestas"," Participantes.id = Propuestas.participante")
                         //perfil = 17  perfil de jurado
                        ->where("Usuariosperfiles.perfil = 17 ")
                        ->andWhere("Usuariosperfiles.usuario = ".$user_current["id"])
                        ->andWhere("Propuestas.convocatoria = ".$request->get('idc'))
                        ->execute()
                        ->getFirst();*/

                        //$participante = Participantes::findFirst($request->get('participante'));
                        //$postulacion = Juradospostulados::findFirst($request->get('participante'));
                        //$participante = $postulacion->propuestas->participantes;


                       if( $request->get('postulacion') && $request->get('postulacion') == 'null'){

                           $participante = Participantes::findFirst($request->get('participante'));
                       }elseif( $request->get('postulacion') && $request->get('postulacion') != 'null'){
                         $postulacion = Juradospostulados::findFirst($request->get('postulacion'));
                         $participante = $postulacion->propuestas->participantes;
                       }

                       $experiencialaborales = Experiencialaboral::find(
                         [
                           " propuesta= ".$participante->propuestas->id
                           ." AND ( entidad LIKE '%".$request->get("search")['value']."%'"
                           ." OR cargo LIKE '%".$request->get("search")['value']."%' )",
                           "order" => 'id ASC',
                           "limit" =>  $request->get('length'),
                           "offset" =>  $request->get('start'),
                         ]
                       );

                       foreach ($experiencialaborales as $experiencialaboral) {

                         $ciudad =  Ciudades::findFirst(
                           ["id=".$experiencialaboral->ciudad]
                         );
                         $experiencialaboral->ciudad = $ciudad->nombre;

                         $linea =Lineasestrategicas::findFirst(
                           ["id = ".$experiencialaboral->linea]
                         );
                         $experiencialaboral->linea = $linea->nombre;

                         $experiencialaboral->creado_por = null;
                         $experiencialaboral->actualizado_por = null;
                         array_push($response,$experiencialaboral);
                       }

                       //resultado sin filtro
                       $texperiencialaboral = Experiencialaboral::find([
                          " propuesta= ".$participante->propuestas->id
                          ." AND entidad LIKE '%".$request->get("search")['value']."%'"
                          ." OR cargo LIKE '%".$request->get("search")['value']."%'"
                       ]);



            }


            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($texperiencialaboral->count()),
                "recordsFiltered" => intval($texperiencialaboral->count()),
                "data" => $response   // total data array
            );
            //retorno el array en json
           return json_encode($json_data);

        } else {
            return "error_token";
        }
    } catch (Exception $ex) {

      //  echo "error_metodo";

      //Para auditoria en versión de pruebas
      return "error_metodo" . $ex->getMessage().$ex->getTraceAsString ();
    }
}
);

//Busca los registros de educacion formal
$app->get('/all_experiencia_jurado', function () use ($app, $config) {
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


                /*      $participante = Participantes::query()
                        ->join("Usuariosperfiles","Participantes.usuario_perfil = Usuariosperfiles.id")
                        ->join("Propuestas"," Participantes.id = Propuestas.participante")
                         //perfil = 17  perfil de jurado
                        ->where("Usuariosperfiles.perfil = 17 ")
                        ->andWhere("Usuariosperfiles.usuario = ".$user_current["id"])
                        ->andWhere("Propuestas.convocatoria = ".$request->get('idc'))
                        ->execute()
                        ->getFirst();*/

                       //$participante = Participantes::findFirst($request->get('participante'));

                       //$postulacion = Juradospostulados::findFirst($request->get('participante'));
                       //$participante = $postulacion->propuestas->participantes;


                        if( $request->get('postulacion') && $request->get('postulacion') == 'null'){

                            $participante = Participantes::findFirst($request->get('participante'));
                        }elseif( $request->get('postulacion') && $request->get('postulacion') != 'null'){
                          $postulacion = Juradospostulados::findFirst($request->get('postulacion'));
                          $participante = $postulacion->propuestas->participantes;
                        }


                       $experienciajurados = Experienciajurado::find(
                         [
                           " propuesta = ".$participante->propuestas->id
                           ." AND ( nombre LIKE '%".$request->get("search")['value']."%'"
                           ." OR entidad LIKE '%".$request->get("search")['value']."%'"
                           ." OR anio LIKE '%".$request->get("search")['value']."%' )",
                           "order" => 'id ASC',
                           "limit" =>  $request->get('length'),
                           "offset" =>  $request->get('start'),
                         ]
                       );

                       foreach ($experienciajurados as $experienciajurado) {

                         $ciudad =  Ciudades::findFirst(
                           ["active=true AND id=".$experienciajurado->ciudad]
                         );
                         $experienciajurado->ciudad = $ciudad->nombre;

                         $ambito = Categoriajurado::findFirst(
                           ["active=true AND id=".$experienciajurado->ambito]
                         );
                          $experienciajurado->ambito = $ambito->nombre;

                         $experienciajurado->creado_por = null;
                         $experienciajurado->actualizado_por = null;
                         array_push($response,$experienciajurado);
                       }

                       //resultado sin filtro
                       $texperienciajurado = Experienciajurado::find(
                         [
                           " propuesta = ".$participante->propuestas->id
                           ." AND nombre LIKE '%".$request->get("search")['value']."%'"
                           ." OR entidad LIKE '%".$request->get("search")['value']."%'"
                           ." OR anio LIKE '%".$request->get("search")['value']."%'",
                        ]
                      );



            }

            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($texperienciajurado->count()),
                "recordsFiltered" => intval($experienciajurados->count()),
                "data" => $response   // total data array
            );
            //retorno el array en json
           return json_encode($json_data);

        } else {
            return "error_token";
        }
    } catch (Exception $ex) {

      //  echo "error_metodo";
      //Para auditoria en versión de pruebas
      return "error_metodo" . $ex->getMessage().$ex->getTraceAsString ();
    }
}
);

//Busca los registros de reconocimiento
$app->get('/all_reconocimiento', function () use ($app, $config) {
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


                    /*  $participante = Participantes::query()
                        ->join("Usuariosperfiles","Participantes.usuario_perfil = Usuariosperfiles.id")
                        ->join("Propuestas"," Participantes.id = Propuestas.participante")
                         //perfil = 17  perfil de jurado
                        ->where("Usuariosperfiles.perfil = 17 ")
                        ->andWhere("Usuariosperfiles.usuario = ".$user_current["id"])
                        ->andWhere("Propuestas.convocatoria = ".$request->get('idc'))
                        ->execute()
                        ->getFirst();*/

                        //$participante = Participantes::findFirst($request->get('participante'));

                        //$postulacion = Juradospostulados::findFirst($request->get('participante'));
                        //$participante = $postulacion->propuestas->participantes;


                        if( $request->get('postulacion') && $request->get('postulacion') == 'null'){

                            $participante = Participantes::findFirst($request->get('participante'));
                        }elseif( $request->get('postulacion') && $request->get('postulacion') != 'null'){
                          $postulacion = Juradospostulados::findFirst($request->get('postulacion'));
                          $participante = $postulacion->propuestas->participantes;
                        }

                       $reconocimientos = Propuestajuradoreconocimiento::find(
                         [
                           " propuesta= ".$participante->propuestas->id
                           ." AND ( nombre LIKE '%".$request->get("search")['value']."%'"
                           ." OR institucion LIKE '%".$request->get("search")['value']."%'"
                           ." OR anio LIKE '%".$request->get("search")['value']."%' )",
                           "order" => 'id ASC',
                           "limit" =>  $request->get('length'),
                           "offset" =>  $request->get('start'),
                         ]
                       );

                       foreach ($reconocimientos as $reconocimiento) {

                         $ciudad =  Ciudades::findFirst(
                           ["active=true AND id=".$reconocimiento->ciudad]
                         );
                         $reconocimiento->ciudad = $ciudad->nombre;

                         $tipo = Categoriajurado::findFirst(
                           ["active=true AND id=".$reconocimiento->tipo]
                         );
                         $reconocimiento->tipo = $tipo->nombre;

                         $reconocimiento->creado_por = null;
                         $reconocimiento->actualizado_por = null;
                         array_push($response,$reconocimiento);
                       }

                       //resultado sin filtro
                       $treconocimiento = Propuestajuradoreconocimiento::find(
                         [
                           " propuesta= ".$participante->propuestas->id
                           ." AND (nombre LIKE '%".$request->get("search")['value']."%'"
                           ." OR institucion LIKE '%".$request->get("search")['value']."%'"
                           ." OR anio LIKE '%".$request->get("search")['value']."%')"
                        ]
                      );



            }


            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval( $treconocimiento ->count()),
                "recordsFiltered" => intval($reconocimientos->count()),
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

//Busca los registros de educacion formal
$app->get('/all_publicacion', function () use ($app, $config) {
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

                      /*$participante = Participantes::query()
                        ->join("Usuariosperfiles","Participantes.usuario_perfil = Usuariosperfiles.id")
                        ->join("Propuestas"," Participantes.id = Propuestas.participante")
                         //perfil = 17  perfil de jurado
                        ->where("Usuariosperfiles.perfil = 17 ")
                        ->andWhere("Usuariosperfiles.usuario = ".$user_current["id"])
                        ->andWhere("Propuestas.convocatoria = ".$request->get('idc'))
                        ->execute()
                        ->getFirst();
                        */
                      //$participante = Participantes::findFirst($request->get('participante'));

                      //$postulacion = Juradospostulados::findFirst($request->get('participante'));
                      //$participante = $postulacion->propuestas->participantes;

                      if( $request->get('postulacion') && $request->get('postulacion') == 'null'){

                          $participante = Participantes::findFirst($request->get('participante'));
                      }elseif( $request->get('postulacion') && $request->get('postulacion') != 'null'){
                        $postulacion = Juradospostulados::findFirst($request->get('postulacion'));
                        $participante = $postulacion->propuestas->participantes;
                      }

                       $publicaciones = Propuestajuradopublicacion::find(
                         [
                           " propuesta= ".$participante->propuestas->id
                           ." AND ( titulo LIKE '%".$request->get("search")['value']."%'"
                           ." OR tema LIKE '%".$request->get("search")['value']."%'"
                           ." OR anio LIKE '%".$request->get("search")['value']."%' )",
                           "order" => 'id ASC',
                           "limit" =>  $request->get('length'),
                           "offset" =>  $request->get('start'),
                         ]
                       );

                       foreach ($publicaciones as $publicacion) {

                         $ciudad =  Ciudades::findFirst(
                           ["active=true AND id=".$publicacion->ciudad]
                         );
                         $publicacion->ciudad = $ciudad->nombre;

                         $tipo = Categoriajurado::findFirst(
                           ["active=true AND id=".$publicacion->tipo]
                         );
                         $publicacion->tipo = $tipo->nombre;

                         $formato = Categoriajurado::findFirst(
                           ["active=true AND id=".$publicacion->formato]
                         );
                         $publicacion->formato = $formato->nombre;

                         $publicacion->creado_por = null;
                         $publicacion->actualizado_por = null;
                         array_push($response,$publicacion);
                       }

                       //resultado sin filtro
                       $tpublicacion = Propuestajuradopublicacion::find(
                         [
                         " propuesta= ".$participante->propuestas->id
                         ." AND titulo LIKE '%".$request->get("search")['value']."%'"
                         ." OR tema LIKE '%".$request->get("search")['value']."%'"
                         ." OR anio LIKE '%".$request->get("search")['value']."%'"
                          ]
                        );



            }


            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval( $tpublicacion ->count()),
                "recordsFiltered" => intval($publicaciones->count()),
                "data" => $response   // total data array
            );
            //retorno el array en json
           return json_encode($json_data);

        } else {
            return "error_token";
        }
    } catch (Exception $ex) {

      //  echo "error_metodo";

      //Para auditoria en versión de pruebas
      return "error_metodo" . $ex->getMessage().$ex->getTraceAsString ();
    }
}
);

/*
*Carga los datos relacionados con los criterios de evaluacion
* ronda, postulacion, criterios
*/
$app->get('/criterios_evaluacion', function () use ($app, $config) {
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


              /*$participante = Participantes::query()
                ->join("Usuariosperfiles","Participantes.usuario_perfil = Usuariosperfiles.id")
                ->join("Propuestas"," Participantes.id = Propuestas.participante")
                 //perfil = 17  perfil de jurado
                ->where("Usuariosperfiles.perfil = 17 ")
                ->andWhere("Usuariosperfiles.usuario = ".$user_current["id"])
                ->andWhere("Propuestas.convocatoria = ".$request->get('idc'))
                ->execute()
                ->getFirst();
                */
            //  $participante = Participantes::findFirst($request->get('participante'));
            // $rondas = $participante->propuestas->convocatorias->convocatoriasrondas;

              $postulacion = Juradospostulados::findFirst($request->get('postulacion'));

              $rondas = $postulacion->propuestas->convocatorias->convocatoriasrondas;

                //echo  json_encode($rondas);
              foreach ($rondas as $ronda) {

                if($ronda->active){

                    //se construye el array de grupos d ecriterios
                    $grupo_criterios = array();
                    //se cronstruye el array de criterios
                    $criterios = array();

                    //Se crea el array en el orden de los criterios
                    foreach ($ronda->Convocatoriasrondascriterios as $criterio) {

                      if($criterio->active){
                        $grupo_criterios[$criterio->grupo_criterio]= $criterio->orden;
                      }


                    }


                    //de acuerdo con el orden, se crea al array de criterios
                    foreach ($grupo_criterios as $categoria => $orden) {

                      //$obj = ["grupo" => $categoria, "criterios"=> array()];
                      $obj= array();
                      $obj[$categoria] = array();

                      foreach ($ronda->Convocatoriasrondascriterios as $criterio) {

                        if( $criterio->active && $criterio->grupo_criterio === $categoria ){
                          // $obj[$categoria][$criterio->orden]=  $criterio;
                          $obj[$categoria][$criterio->orden]= [
                                                                "id"=>$criterio->id,
                                                                "descripcion_criterio"=>$criterio->descripcion_criterio,
                                                                "puntaje_minimo"=>$criterio->puntaje_minimo,
                                                                "puntaje_maximo"=>$criterio->puntaje_maximo,
                                                                "orden"=>$criterio->orden,
                                                                "grupo_criterio"=>$criterio->grupo_criterio,
                                                                "exclusivo"=>$criterio->exclusivo,
                                                                "evaluacion"=> Evaluacion::findFirst([
                                                                  "criterio = ".$criterio->id
                                                                  ." AND postulado = ".$postulacion->id
                                                                ])
                                                              ];

                        }

                      }


                      $criterios[$orden]= $obj ;
                  }


                    $response[$ronda->numero_ronda]= ["ronda"=>$ronda,"postulacion"=>$postulacion,"criterios"=>$criterios];

                  }

              }


            }
            //retorno el array en json
           return json_encode($response);

        } else {
            return "error_token";
        }
    } catch (Exception $ex) {

      //  echo "error_metodo";

      //Para auditoria en versión de pruebas
      return "error_metodo" . $ex->getMessage().$ex->getTraceAsString ();
    }
}
);

// Actualiza a evaluación del perfil
$app->put('/evaluar_perfil', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //$chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

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

            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                $user_current = json_decode($token_actual->user_current, true);

                if( $request->getPut('postulacion') && $request->getPut('postulacion') !='' ){

                      $postulacion = Juradospostulados::findFirst($request->getPut('postulacion'));
                      $postulacion->actualizado_por = $user_current["id"];
                      $postulacion->fecha_actualizacion =  date("Y-m-d H:i:s");

                }else{
                  $participante = Participantes::findFirst($request->getPut('participante'));

                  $postulacion = new Juradospostulados();

                  $postulacion->propuesta = $participante->Propuestas->id;
                  $postulacion->creado_por = $user_current["id"];
                  $postulacion->fecha_creacion =  date("Y-m-d H:i:s");
                }

              //  echo json_encode($postulacion);
            //    $participante = $postulacion->propuestas->participantes;

              /*  $propuesta = Propuestas::findFirst(
                  [
                    "participante = ".$request->getPut('participante')
                  ]
                );

                $postulacion = Juradospostulados::findFirst(
                  [
                    " propuesta = ".$propuesta->id
                    ." AND convocatoria = ".$request->getPut('idc')
                  ]
                );*/

                if(!$request->getPut('option_aplica_perfil')){
                  return "error";
                }



                if( $request->getPut('categoria') && $request->getPut('categoria') !='' ){
                    $postulacion->convocatoria = $request->getPut('categoria');
                }else{
                    $postulacion->convocatoria = $request->getPut('idc');
                }

                $postulacion->descripcion_evaluacion = $request->getPut('descripcion_evaluacion');
                $postulacion->aplica_perfil = $request->getPut('option_aplica_perfil');
                $postulacion->estado = 11; //11	jurados	Verificado
                $postulacion->active =  true;


                //actualiza la postulación
                if( $postulacion->save() === false ){
                  //return "error";

                  //Para auditoria en versión de pruebas
                  foreach ($postulacion->getMessages() as $message) {
                       echo $message;
                     }

                  return json_encode($postulacion);
                }

                //echo "--->".json_encode($postulacion->Convocatorias->Convocatorias->Categorias);
                $convocatoria_padre =$postulacion->Convocatorias->Convocatorias;

                //La convocatoria a la cual esta postulado tiene padre? si, es una categoria ;
                // La convocatoria padre tiene categorias iguales?
                //La convocatoria padre mismos_jurados_categoria?
                if( $convocatoria_padre && !$convocatoria_padre->diferentes_categorias && $convocatoria_padre->mismos_jurados_categorias){

                  // Start a transaction
                  $this->db->begin();

                  foreach ($convocatoria_padre->Categorias as $key => $categoria) {

                    if( $postulacion->Convocatorias->id != $categoria->id){

                      $new_postulacion = new Juradospostulados();
                      $new_postulacion->propuesta = $postulacion->propuesta;
                      $new_postulacion->convocatoria = $categoria->id;
                      $new_postulacion->tipo_postulacion = $postulacion->tipo_postulacion;
                      $new_postulacion->estado = $postulacion->estado;
                      $new_postulacion->creado_por = $user_current["id"];
                      $new_postulacion->fecha_creacion =  date("Y-m-d H:i:s");
                      $new_postulacion->active = $postulacion->active;
                      $new_postulacion->descripcion_evaluacion = $postulacion->descripcion_evaluacion;
                      $new_postulacion->aplica_perfil = $postulacion->aplica_perfil;
                      $new_postulacion->perfil = $postulacion->perfil;

                      // The model failed to save, so rollback the transaction
                      if ( $new_postulacion->save() === false ) {
                        //Para auditoria en versión de pruebas
                        foreach ($new_postulacion->getMessages() as $message) {
                             echo $message;
                           }

                        $this->db->rollback();
                        return "error";
                      }

                    }

                  }

                  // Commit the transaction
                  $this->db->commit();

                }


                echo $postulacion->id;



            } else {
                return "acceso_denegado";
            }
        } else {
            return "error_token";
        }

    } catch (Exception $ex) {
        //return "error_metodo";
        //Para auditoria en versión de pruebas
        return "error_metodo" .  $ex->getMessage().json_encode($ex->getTrace());

    }
}
);

// Crear registro
$app->post('/evaluar_criterios', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $response = array();
        $total_evaluacion=0;
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

                /*
                $propuesta = Propuestas::findFirst(
                  [
                    "participante=".$request->getPost('participante')
                  ]
                );

                $juradospostulado = Juradospostulados::findFirst(
                  [
                    " propuesta = ".$propuesta->id
                    ." AND convocatoria = ".$request->getPost('idc')
                  ]
                );
                */

                $juradospostulado = Juradospostulados::findFirst($request->get('postulacion'));

                //12	jurados	Evaluado
                //13	jurados	Seleccionado
                if ( $juradospostulado->estado != 12 &&  $juradospostulado->estado != 13){

                  $criterios = Convocatoriasrondascriterios::find(
                    [
                      "convocatoria_ronda=".$request->getPost('ronda')
                      ." AND active = true"
                    ]
                  );

                  // Start a transaction
                  $this->db->begin();

                  $convocatoria_padre = $juradospostulado->Convocatorias->Convocatorias;

                  if( $convocatoria_padre && !$convocatoria_padre->diferentes_categorias && $convocatoria_padre->mismos_jurados_categorias){
                    $convocatorias = $convocatoria_padre->Categorias;

                    $conv = array();
                    foreach ($convocatorias as $key => $value) {
                      array_push($conv, $value->id);
                    }

                    $postulaciones = Juradospostulados::find(
                      [
                        'propuesta = '.$juradospostulado->propuesta
                        .' AND convocatoria IN ({convocatorias:array})',
                        'bind' => [
                            'convocatorias' => $conv
                        ]
                      ]
                    );

                  }else{
                    $convocatorias= array( $juradospostulado->Convocatorias );
                    $postulaciones = array($juradospostulado);
                  }

                //  echo json_encode($convocatorias);
                //  echo json_encode($postulaciones);

                  foreach ($postulaciones as $key => $juradospostulado) {

                    foreach ($criterios as $key => $criterio) {

                      $evaluacion_criterio = new Evaluacion();
                      $evaluacion_criterio->propuesta = $juradospostulado->propuesta;
                      $evaluacion_criterio->criterio = $criterio->id;
                      $evaluacion_criterio->puntaje = $request->getPost( (string)$criterio->id );
                      $evaluacion_criterio->creado_por = $user_current["id"];
                      $evaluacion_criterio->fecha_creacion =  date("Y-m-d H:i:s");
                      $evaluacion_criterio->postulado = $juradospostulado->id;

                      // The model failed to save, so rollback the transaction
                      if ($evaluacion_criterio->save() === false) {
                        //Para auditoria en versión de pruebas
                        foreach ($evaluacion_criterio->getMessages() as $message) {
                             echo $message;
                           }

                        $this->db->rollback();
                        return "error";
                      }

                      array_push($response,$evaluacion_criterio->id);
                      $total_evaluacion = $total_evaluacion+$evaluacion_criterio->puntaje;


                    }


                    $juradospostulado->total_evaluacion = $total_evaluacion;
                    $juradospostulado->estado = 12; //12	jurados	Evaluado
                    $juradospostulado->actualizado_por = $user_current["id"];
                    $juradospostulado->fecha_actualizacion =  date("Y-m-d H:i:s");

                    // The model failed to save, so rollback the transaction
                    if ($juradospostulado->save() === false) {
                      $this->db->rollback();
                      return "error";
                    }

                      $total_evaluacion = 0;

                  }



                  // Commit the transaction
                  $this->db->commit();
                  return json_encode($response);
                }else{
                  return "error";
                }


            } else {
                return "acceso_denegado";
            }
        } else {
            return "error_token";
        }

    } catch (Exception $ex) {
        //return "error_metodo";
        //Para auditoria en versión de pruebas
        return "error_metodo" .  $ex->getMessage().json_encode($ex->getTrace());

    }
}
);

// Actualiza el estado de la postulacion
$app->put('/seleccionar_perfil', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //$chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

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

            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {


                $user_current = json_decode($token_actual->user_current, true);

                $postulacion = Juradospostulados::findFirst($request->getPut('postulacion'));

                $postulacion->estado = 12; //12	jurados	Seleccionado
                $postulacion->actualizado_por = $user_current["id"];
                $postulacion->fecha_actualizacion =  date("Y-m-d H:i:s");

                //caso 2
                if($postulacion->convocatorias->tiene_categorias && !$postulacion->convocatorias->diferentes_categorias && $request->getPut('idcat')){

                    //13	jurados	Seleccionado
                    $phql = 'UPDATE Juradospostulados SET estado = 13 , actualizado_por = ?0, fecha_actualizacion = ?1, convocatoria = ?2 WHERE id = ?3';

                    $result =  $app->modelsManager->executeQuery(
                          $phql,
                          [
                              0 => $user_current["id"],
                              1 => date("Y-m-d H:i:s"),
                              2 => $request->getPut('idcat'),
                              3=> $postulacion->id,
                          ]
                        );


                      if ($result->success() === false) {
                          $messages = $result->getMessages();

                          foreach ($messages as $message) {
                              echo $message->getMessage();
                          }
                      }

                }else{

                  //se actualiza la postulación
                  if( $postulacion->update() === false ){
                    //return "error";

                    //Para auditoria en versión de pruebas
                    foreach ($postulacion->getMessages() as $message) {
                         echo $message;
                       }

                    return json_encode($postulacion);
                  }

                  echo $postulacion->id;

                }




            } else {
                return "acceso_denegado";
            }
        } else {
            return "error_token";
        }

    } catch (Exception $ex) {
        //return "error_metodo";
        //Para auditoria en versión de pruebas
        return "error_metodo" .  $ex->getMessage().json_encode($ex->getTrace());

    }
}
);

//Busca los registros de educacion formal
$app->get('/convocatoria', function () use ($app, $config) {
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

             if( $request->get('idcat') ){

               $convocatoria =  Convocatorias::findFirst( $request->get('idcat') );
               //retorno el array en json
               return json_encode($convocatoria);

             }elseif ($request->get('convocatoria')) {
               $convocatoria =  Convocatorias::findFirst( $request->get('convocatoria') );
               //retorno el array en json
               return json_encode($convocatoria);

             }else{
                return "error";
             }

           }


        } else {
            return "error_token";
        }
    } catch (Exception $ex) {

      //  echo "error_metodo";

      //Para auditoria en versión de pruebas
      return "error_metodo" . $ex->getMessage().$ex->getTraceAsString ();
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
