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

        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

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

                           //echo "error";

                           //Para auditoria en versión de pruebas

                           foreach ($participante->getMessages() as $message) {
                                   echo $message;
                                 }

                         }else{

                            //Se crea la carpeta donde se guardaran los documentos de la propuesta (hoja de vida) del jurado
                            $filepath = "/Sites/convocatorias/".$propuesta->convocatoria."/propuestas/";
                            //echo "ruta-->>".$filepath.$new_participante->propuestas->id;
                            $return =   $chemistry_alfresco->newFolder($filepath, $new_participante->propuestas->id);
                            //echo $return;
                            if(strpos($return, "Error") !== FALSE){
                                echo "error_creo_alfresco";
                            }

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
      echo "error_metodo: ". $ex->getMessage().json_encode($ex->getTrace());
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
                  //valido si la propuesta tiene el estado registrada

                  if( $participante->propuestas  != null and $participante->propuestas->estado == 7 ){

                      $participante->propuestas->modalidad_participa = $request->get('categoria');
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

                         //Select Área de conocimineto*
                         //$array["area_conocimiento"] = Areasconocimientos::find("active=true");
                         $tipos = Categoriajurado::find("active=true AND tipo='formal' and id=nodo");
                         $array["area_conocimiento"]=array();
                         foreach ( $tipos as $tipo) {
                           array_push($array["area_conocimiento"], ["id"=> $tipo->id, "nombre"=> $tipo->nombre ] );
                         }

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

                         $ciudad =  Ciudades::findFirst(
                           ["active=true AND id=".$educacionformal->ciudad]
                         );

                         $educacionformal->ciudad = $ciudad->nombre;
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
              //foreach ( $rs as $key => $value) {
                //      $nucleosbasicos[$key]= array("id"=>$value->id, "nombre"=>$value->nombre);
                //}

                $tipos =Categoriajurado::find("active=true AND tipo='formal' AND nodo=".$request->get('id'));
                $nucleosbasicos=array();
                foreach ( $tipos as $tipo) {
                  array_push($nucleosbasicos, ["id"=> $tipo->id, "nombre"=> $tipo->nombre ] );
                }


            }

            echo json_encode($nucleosbasicos);
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        return "error_metodo".$ex->getMessage();
    }
}
);

// Crea el registro
$app->post('/new_educacion_formal', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

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
                $post = $app->request->getPost();

                $user_current = json_decode($token_actual->user_current, true);

                // Si el usuario que inicio sesion tine registro de  participante  con el perfil de jurado
                    $usuario_perfil  = Usuariosperfiles::findFirst(
                      [
                        " usuario = ".$user_current["id"]." AND perfil =17 "
                      ]
                    );

                     if( $usuario_perfil->id != null ){

                        $participante = Participantes::query()
                          ->join("Usuariosperfiles","Participantes.usuario_perfil = Usuariosperfiles.id")
                          ->join("Propuestas"," Participantes.id = Propuestas.participante")
                           //perfil = 17  perfil de jurado
                          ->where("Usuariosperfiles.perfil = 17 ")
                          ->andWhere("Usuariosperfiles.usuario = ".$user_current["id"])
                          ->andWhere("Propuestas.convocatoria = ".$request->getPost('idc'))
                          ->execute()
                          ->getFirst();

                        //valido si la propuesta tiene el estado registrada
                      //if( $propuesta != null and $propuesta->estado == 7 ){
                        if( $participante->propuestas != null and $participante->propuestas->estado == 7 ){

                         $educacionformal = new Educacionformal();
                         $educacionformal->creado_por = $user_current["id"];
                         $educacionformal->fecha_creacion = date("Y-m-d H:i:s");
                         $educacionformal->active = true;
                         //al asignarle un objeto genera error, por tal motivo se envia solo el id
                         $educacionformal->propuesta = $participante->propuestas->id;
                         $educacionformal->usuario_perfil = $participante->usuario_perfil;

                         $post["id"]= null;

                        //echo "educacionformal---->>".json_encode($educacionformal);
                        //  echo "post---->>".json_encode($post);
                         if ($educacionformal->save($post) === false) {
                             echo "error";
                             //Para auditoria en versión de pruebas
                             foreach ($educacionformal->getMessages() as $message) {
                                  echo $message;
                                }

                         } else {

                           //echo "guardando archivo";
                           //Recorro todos los posibles archivos
                           foreach($_FILES as $clave => $valor){
                               $fileTmpPath = $valor['tmp_name'];
                               $fileType = $valor['type'];
                               $fileNameCmps = explode(".", $valor["name"]);
                               $fileExtension = strtolower(end($fileNameCmps));
                              // $fileName = "c".$request->getPost('convocatoria_padre_categoria')."d".$convocatoriaanexo->id."u".$convocatoriaanexo->creado_por."f".date("YmdHis").".".$fileExtension;
                              // $return = $chemistry_alfresco->newFile("/Sites/convocatorias/".$request->getPost('convocatoria_padre_categoria')."/".$request->getPost('anexos')."/", $fileName, file_get_contents($fileTmpPath), $fileType);
                              /*
                              UPLOAD_ERR_OK
                              Valor: 0; No hay error, fichero subido con éxito.

                               UPLOAD_ERR_INI_SIZE
                               Valor: 1; El fichero subido excede la directiva upload_max_filesize de php.ini.

                               UPLOAD_ERR_FORM_SIZE
                               Valor: 2; El fichero subido excede la directiva MAX_FILE_SIZE especificada en el formulario HTML.

                               UPLOAD_ERR_PARTIAL
                               Valor: 3; El fichero fue sólo parcialmente subido.

                               UPLOAD_ERR_NO_FILE
                               Valor: 4; No se subió ningún fichero.

                               UPLOAD_ERR_NO_TMP_DIR
                               Valor: 6; Falta la carpeta temporal. Introducido en PHP 5.0.3.

                               UPLOAD_ERR_CANT_WRITE
                               Valor: 7; No se pudo escribir el fichero en el disco. Introducido en PHP 5.1.0.

                               UPLOAD_ERR_EXTENSION
                               Valor: 8; Una extensión de PHP detuvo la subida de ficheros. PHP no proporciona una forma de determinar la extensión que causó la parada de la subida de ficheros; el examen de la lista de extensiones cargadas con phpinfo() puede ayudar. Introducido en PHP 5.2.0.
                              */
                             if($valor['error'] == 0){
                              /*
                              * propuesta[codigo]educacionformal[codigo]usuario[codigo]fecha[YmdHis].extension
                              * p(cod)ef(cod)u(cod)f(YmdHis).(ext)
                              */
                               $fileName = "p".$educacionformal->propuesta."ef".$educacionformal->id."u".$user_current["id"]."f".date("YmdHis").".".$fileExtension;
                               $filepath = "/Sites/convocatorias/".$request->getPost('idc')."/propuestas/".$educacionformal->propuesta;
                               $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);

                               //echo "archivo".$fileName;
                               //echo "path".$filepath;
                               if(strpos($return, "Error") !== FALSE){
                                   //echo "    ".json_encode($return);
                                   echo "error_creo_alfresco";
                               }else{

                                   $educacionformal->file = $return;
                                   if ($educacionformal->save() === false) {
                                       echo "error";
                                      //Para auditoria en versión de pruebas
                                      foreach ($educacionformal->getMessages() as $message) {
                                           echo $message;
                                         }
                                   }

                               }
                             }else{
                               //echo "error".$valor['error'];
                             }

                           }

                           return $educacionformal->id;

                         }

                       }else{
                         return "deshabilitado";
                       }

                     } else {
                           return "error";
                       }

            } else {
                return "acceso_denegado";
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
    }
}
);

// Edita el registro
$app->post('/edit_educacion_formal/{id:[0-9]+}', function ($id) use ($app, $config) {

  try {


        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

        //echo "put-->".json_encode($request->getPost());

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //echo "token--->".json_encode($token_actual);

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
                         ->andWhere("Propuestas.convocatoria = ".$request->getPost('idc'))
                         ->execute()
                         ->getFirst();

                         //valido si la propuesta tiene el estado registrada
                       if( $participante->propuestas != null and $participante->propuestas->estado == 7 ){

                           $educacionformal = Educacionformal::findFirst($id);
                           $educacionformal->actualizado_por = $user_current["id"];
                           $educacionformal->fecha_actualizacion = date("Y-m-d H:i:s");

                        //  echo "educacionformal---->>".json_encode($educacionformal);
                            //echo "post---->>".json_encode($post);
                           if ($educacionformal->save($post) === false) {

                             //  return json_encode($user_current);
                             echo "error";
                             //Para auditoria en versión de pruebas
                             foreach ($educacionformal->getMessages() as $message) {
                               echo $message;
                             }

                           } else {

                             //echo "file-->".json_encode($_FILES);
                             //Recorro todos los posibles archivos
                             foreach($_FILES as $clave => $valor){
                                 $fileTmpPath = $valor['tmp_name'];
                                 $fileType = $valor['type'];
                                 $fileNameCmps = explode(".", $valor["name"]);
                                 $fileExtension = strtolower(end($fileNameCmps));

                                 /*
                                 UPLOAD_ERR_OK
                                 Valor: 0; No hay error, fichero subido con éxito.

                                  UPLOAD_ERR_INI_SIZE
                                  Valor: 1; El fichero subido excede la directiva upload_max_filesize de php.ini.

                                  UPLOAD_ERR_FORM_SIZE
                                  Valor: 2; El fichero subido excede la directiva MAX_FILE_SIZE especificada en el formulario HTML.

                                  UPLOAD_ERR_PARTIAL
                                  Valor: 3; El fichero fue sólo parcialmente subido.

                                  UPLOAD_ERR_NO_FILE
                                  Valor: 4; No se subió ningún fichero.

                                  UPLOAD_ERR_NO_TMP_DIR
                                  Valor: 6; Falta la carpeta temporal. Introducido en PHP 5.0.3.

                                  UPLOAD_ERR_CANT_WRITE
                                  Valor: 7; No se pudo escribir el fichero en el disco. Introducido en PHP 5.1.0.

                                  UPLOAD_ERR_EXTENSION
                                  Valor: 8; Una extensión de PHP detuvo la subida de ficheros. PHP no proporciona una forma de determinar la extensión que causó la parada de la subida de ficheros; el examen de la lista de extensiones cargadas con phpinfo() puede ayudar. Introducido en PHP 5.2.0.
                                 */
                                if($valor['error'] == 0){
                                /*
                                * propuesta[codigo]educacionformal[codigo]usuario[codigo]fecha[YmdHis].extension
                                * p(cod)ef(cod)u(cod)f(YmdHis).(ext)
                                */
                                 $fileName = "p".$educacionformal->propuesta."ef".$educacionformal->id."u".$user_current["id"]."f".date("YmdHis").".".$fileExtension;
                                 $filepath = "/Sites/convocatorias/".$request->getPost('idc')."/propuestas/".$educacionformal->propuesta;
                                 $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);

                                 if(strpos($return, "Error") !== FALSE){
                                      //echo "    ".json_encode($return);
                                     echo "error_creo_alfresco";
                                 }else{

                                     $educacionformal->file = $return;
                                     if ($educacionformal->save() === false) {
                                         echo "error";
                                        //Para auditoria en versión de pruebas
                                        foreach ($educacionformal->getMessages() as $message) {
                                             echo $message;
                                           }
                                     }

                                 }
                               }else{
                                 //echo "error".$valor['error'];

                               }

                             }

                               return $educacionformal->id;
                           }
                       }else{
                         return "deshabilitado";
                       }

                     } else {
                           return "error";
                       }

            } else {
                return "acceso_denegado";
            }
        } else {
            return "error_token";
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

                    $participante = Participantes::query()
                      ->join("Usuariosperfiles","Participantes.usuario_perfil = Usuariosperfiles.id")
                      ->join("Propuestas"," Participantes.id = Propuestas.participante")
                       //perfil = 17  perfil de jurado
                      ->where("Usuariosperfiles.perfil = 17 ")
                      ->andWhere("Usuariosperfiles.usuario = ".$user_current["id"])
                      ->andWhere("Propuestas.convocatoria = ".$request->getPut('idc'))
                      ->execute()
                      ->getFirst();

                      //valido si la propuesta tiene el estado registrada
                    if( $participante->propuestas != null and $participante->propuestas->estado == 7 ){

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
                        foreach ($educacionformal->getMessages() as $message) {
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



//Busca el registro educación formal
$app->get('/search_educacion_no_formal', function () use ($app, $config) {
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

                             $educacionnoformal=Educacionnoformal::findFirst( $request->get('idregistro') );

                       }


                        $array["usuario_perfil"]=$usuario_perfil->id;

                         //Ciudades
                         foreach( Ciudades::find("active=true") as $value )
                         {
                             $array_ciudades[]=array("id"=>$value->id,"label"=>$value->nombre." - ".$value->getDepartamentos()->nombre." - ".$value->getDepartamentos()->getPaises()->nombre,"value"=>$value->nombre);

                             if($educacionnoformal->ciudad != null && $educacionnoformal->ciudad == $value->id ){
                                $array["ciudad_name"]=$value->nombre;
                             }

                         }
                         $array["ciudad"]=$array_ciudades;

                        $tipos =Tablasmaestras::findFirst("active=true AND nombre='tipo_educacion_no_formal'");
                        $array["tipo"]=array();
                        foreach ( explode(",", $tipos->valor) as $nombre) {
                          array_push($array["tipo"], ["id"=> $nombre, "nombre"=> $nombre ] );
                        }

                        $modalidad=Tablasmaestras::findFirst("active=true AND nombre='modalidad'");
                         $array["modalidad"] =array();
                         foreach ( explode(",", $modalidad->valor) as $nombre) {
                           array_push($array["modalidad"], ["id"=> $nombre, "nombre"=> $nombre ] );
                         }

                         $array["educacionnoformal"] = $educacionnoformal;

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


                       $educacionnoformales = Educacionnoformal::find(
                         [
                           "usuario_perfil= ".$usuario_perfil->id
                           ." AND nombre LIKE '%".$request->get("search")['value']."%'"
                           ." OR institucion LIKE '%".$request->get("search")['value']."%'"
                           ." AND propuesta = ".$participante->propuestas->id,
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
                       $teducacionnoformal = Educacionformal::find([
                         "usuario_perfil = ".$usuario_perfil->id
                       ]);

                     }

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

// Crea el registro
$app->post('/new_educacion_no_formal', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

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
                         ->andWhere("Propuestas.convocatoria = ".$request->getPost('idc'))
                         ->execute()
                         ->getFirst();
                       //valido si la propuesta tiene el estado registrada
                     if( $participante->propuestas != null and $participante->propuestas->estado == 7 ){

                         $educacionnoformal = new Educacionnoformal();
                         $educacionnoformal->creado_por = $user_current["id"];
                         $educacionnoformal->fecha_creacion = date("Y-m-d H:i:s");
                         $educacionnoformal->active = true;
                         //al asignarle un objeto genera error, por tal motivo se envia solo el id
                         $educacionnoformal->propuesta = $participante->propuestas->id;
                         $educacionnoformal->usuario_perfil = $participante->usuario_perfil;

                         $post["id"]= null;

                      //  echo "educacionnoformal---->>".json_encode($educacionnoformal);
                        //  echo "post---->>".json_encode($post);
                         if ($educacionnoformal->save($post) === false) {
                                  //  return json_encode($user_current);
                                  echo "error";
                             //Para auditoria en versión de pruebas
                             foreach ($educacionnoformal->getMessages() as $message) {
                                  echo $message;
                                }

                         } else {

                           echo "guardando archivo";
                           echo json_encode($_FILES);
                           //Recorro todos los posibles archivos
                           foreach($_FILES as $clave => $valor){
                               $fileTmpPath = $valor['tmp_name'];
                               $fileType = $valor['type'];
                               $fileNameCmps = explode(".", $valor["name"]);
                               $fileExtension = strtolower(end($fileNameCmps));
                              // $fileName = "c".$request->getPost('convocatoria_padre_categoria')."d".$convocatoriaanexo->id."u".$convocatoriaanexo->creado_por."f".date("YmdHis").".".$fileExtension;
                              // $return = $chemistry_alfresco->newFile("/Sites/convocatorias/".$request->getPost('convocatoria_padre_categoria')."/".$request->getPost('anexos')."/", $fileName, file_get_contents($fileTmpPath), $fileType);
                              /*
                              UPLOAD_ERR_OK
                              Valor: 0; No hay error, fichero subido con éxito.

                               UPLOAD_ERR_INI_SIZE
                               Valor: 1; El fichero subido excede la directiva upload_max_filesize de php.ini.

                               UPLOAD_ERR_FORM_SIZE
                               Valor: 2; El fichero subido excede la directiva MAX_FILE_SIZE especificada en el formulario HTML.

                               UPLOAD_ERR_PARTIAL
                               Valor: 3; El fichero fue sólo parcialmente subido.

                               UPLOAD_ERR_NO_FILE
                               Valor: 4; No se subió ningún fichero.

                               UPLOAD_ERR_NO_TMP_DIR
                               Valor: 6; Falta la carpeta temporal. Introducido en PHP 5.0.3.

                               UPLOAD_ERR_CANT_WRITE
                               Valor: 7; No se pudo escribir el fichero en el disco. Introducido en PHP 5.1.0.

                               UPLOAD_ERR_EXTENSION
                               Valor: 8; Una extensión de PHP detuvo la subida de ficheros. PHP no proporciona una forma de determinar la extensión que causó la parada de la subida de ficheros; el examen de la lista de extensiones cargadas con phpinfo() puede ayudar. Introducido en PHP 5.2.0.
                              */
                             if($valor['error'] == 0){
                              /*
                              * propuesta[codigo]educacionformal[codigo]usuario[codigo]fecha[YmdHis].extension
                              * p(cod)ef(cod)u(cod)f(YmdHis).(ext)
                              */
                               $fileName = "p".$educacionnoformal->propuesta."enf".$educacionnoformal->id."u".$user_current["id"]."f".date("YmdHis").".".$fileExtension;
                               $filepath = "/Sites/convocatorias/".$request->getPost('idc')."/propuestas/".$educacionnoformal->propuesta;
                               $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);
                              //  echo "    ".json_encode($return);
                               if(strpos($return, "Error") !== FALSE){
                                  //  echo "    ".json_encode($return);
                                   echo "error_creo_alfresco";
                               }else{

                                   $educacionnoformal->file = $return;
                                   if ($educacionnoformal->save() === false) {
                                       echo "error";
                                      //Para auditoria en versión de pruebas
                                      foreach ($educacionnoformal->getMessages() as $message) {
                                           echo $message;
                                         }
                                   }

                               }
                             }else{
                               //echo "error".$valor['error'];
                             }

                           }

                             return $educacionnoformal->id;
                         }

                       }else{
                         return "deshabilitado";
                       }

                     }else {
                           return "error";
                       }

            } else {
                return "acceso_denegado";
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
    }
}
);

// Edita el registro
$app->post('/edit_educacion_no_formal/{id:[0-9]+}', function ($id) use ($app, $config) {

  try {


        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);


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
                         ->andWhere("Propuestas.convocatoria = ".$request->getPost('idc'))
                         ->execute()
                         ->getFirst();

                         //valido si la propuesta tiene el estado registrada
                       if( $participante->propuestas  != null and $participante->propuestas->estado == 7 ){

                           $educacionnoformal = Educacionnoformal::findFirst($id);
                           $educacionnoformal->actualizado_por = $user_current["id"];
                           $educacionnoformal->fecha_actualizacion = date("Y-m-d H:i:s");

                        //  echo "educacionformal---->>".json_encode($educacionformal);
                            //echo "post---->>".json_encode($post);
                           if ($educacionnoformal->save($post) === false) {
                                    //  return json_encode($user_current);
                               echo "error";
                               //Para auditoria en versión de pruebas
                               foreach ($educacionnoformal->getMessages() as $message) {
                                    echo $message;
                                  }

                           } else {

                             //echo "file-->".json_encode($_FILES);
                             //Recorro todos los posibles archivos
                             foreach($_FILES as $clave => $valor){
                                 $fileTmpPath = $valor['tmp_name'];
                                 $fileType = $valor['type'];
                                 $fileNameCmps = explode(".", $valor["name"]);
                                 $fileExtension = strtolower(end($fileNameCmps));

                                if($valor['error'] == 0){
                                /*
                                * propuesta[codigo]educacionnoformal[codigo]usuario[codigo]fecha[YmdHis].extension
                                * p(cod)enf(cod)u(cod)f(YmdHis).(ext)
                                */
                                 $fileName = "p".$educacionnoformal->propuesta."enf".$educacionnoformal->id."u".$user_current["id"]."f".date("YmdHis").".".$fileExtension;
                                 $filepath = "/Sites/convocatorias/".$request->getPost('idc')."/propuestas/".$educacionnoformal->propuesta;
                                 $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);

                                 if(strpos($return, "Error") !== FALSE){
                                      //echo "    ".json_encode($return);
                                     echo "error_creo_alfresco";
                                 }else{

                                     $educacionnoformal->file = $return;
                                     if ($educacionnoformal->save() === false) {
                                         echo "error";
                                        //Para auditoria en versión de pruebas
                                        foreach ($educacionnoformal->getMessages() as $message) {
                                             echo $message;
                                           }
                                     }

                                 }
                               }else{
                                 //echo "error".$valor['error'];

                               }

                             }

                               return $educacionnoformal->id;
                           }
                       }else{
                         return "deshabilitado";
                       }

                     } else {
                           return "error";
                       }

            } else {
                return "acceso_denegado";
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
    }

}
);

// Eliminar registro de los perfiles de las convocatorias
$app->delete('/delete_educacion_no_formal/{id:[0-9]+}', function ($id) use ($app, $config) {
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

                    $participante = Participantes::query()
                      ->join("Usuariosperfiles","Participantes.usuario_perfil = Usuariosperfiles.id")
                      ->join("Propuestas"," Participantes.id = Propuestas.participante")
                       //perfil = 17  perfil de jurado
                      ->where("Usuariosperfiles.perfil = 17 ")
                      ->andWhere("Usuariosperfiles.usuario = ".$user_current["id"])
                      ->andWhere("Propuestas.convocatoria = ".$request->getPut('idc'))
                      ->execute()
                      ->getFirst();

                      //valido si la propuesta tiene el estado registrada
                    if( $participante->propuestas != null and $participante->propuestas->estado == 7 ){

                      $educacionnoformal = Educacionnoformal::findFirst($id);

                      if($educacionnoformal->active==true){
                          $educacionnoformal->active=false;
                          $retorna="No";
                      }else{
                          $educacionnoformal->active=true;
                          $retorna="Si";
                      }

                      $educacionnoformal->actualizado_por = $user_current["id"];
                      $educacionnoformal->fecha_actualizacion = date("Y-m-d H:i:s");

                      if ($educacionnoformal->save($post) === false) {
                        //Para auditoria en versión de pruebas
                        foreach ($educacionnoformal->getMessages() as $message) {
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



//Busca el registro experiencia_laboral
$app->get('/search_experiencia_laboral', function () use ($app, $config) {
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

                             $experiencialaboral=Experiencialaboral::findFirst( $request->get('idregistro') );

                       }


                        $array["usuario_perfil"]=$usuario_perfil->id;

                         //Ciudades
                         foreach( Ciudades::find("active=true") as $value )
                         {
                             $array_ciudades[]=array("id"=>$value->id,"label"=>$value->nombre." - ".$value->getDepartamentos()->nombre." - ".$value->getDepartamentos()->getPaises()->nombre,"value"=>$value->nombre);

                             if($experiencialaboral->ciudad != null && $experiencialaboral->ciudad == $value->id ){
                                $array["ciudad_name"]=$value->nombre;
                             }

                         }
                         $array["ciudad"]=$array_ciudades;

                        $tipos =Tablasmaestras::findFirst("active=true AND nombre='tipo_entidad'");
                        $array["tipo_entidad"]=array();
                        foreach ( explode(",", $tipos->valor) as $nombre) {
                          array_push($array["tipo_entidad"], ["id"=> $nombre, "nombre"=> $nombre ] );
                        }

                        $lineas =Lineasestrategicas::find("active=true");
                        $array["linea"]=array();
                        foreach ( $lineas as $linea) {
                          array_push($array["linea"], ["id"=> $linea->id, "nombre"=> $linea->nombre ] );
                        }

                         $array["experiencialaboral"] = $experiencialaboral;

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


                       $experiencialaborales = Experiencialaboral::find(
                         [
                           "usuario_perfil= ".$usuario_perfil->id
                           ." AND entidad LIKE '%".$request->get("search")['value']."%'"
                           ." OR cargo LIKE '%".$request->get("search")['value']."%'"
                           ." AND propuesta = ".$participante->propuestas->id,
                           "order" => 'id ASC',
                           "limit" =>  $request->get('length'),
                           "offset" =>  $request->get('start'),
                         ]
                       );

                       foreach ($experiencialaborales as $experiencialaboral) {

                         $ciudad =  Ciudades::findFirst(
                           ["active=true AND id=".$experiencialaboral->ciudad]
                         );

                         $experiencialaboral->ciudad = $ciudad->nombre;
                         $experiencialaboral->creado_por = null;
                         $experiencialaboral->actualizado_por = null;
                         array_push($response,$experiencialaboral);
                       }

                       //resultado sin filtro
                       $texperiencialaboral = Experiencialaboral::find([
                         "usuario_perfil = ".$usuario_perfil->id
                       ]);

                     }

            }


            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($texperiencialaboral->count()),
                "recordsFiltered" => intval($texperiencialaboral->count()),
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

// Crea el registro
$app->post('/new_experiencia_laboral', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

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
                         ->andWhere("Propuestas.convocatoria = ".$request->getPost('idc'))
                         ->execute()
                         ->getFirst();

                       //valido si la propuesta tiene el estado registrada
                     if( $participante->propuestas != null and $participante->propuestas->estado == 7 ){

                         $experiencialaboral = new Experiencialaboral();
                         $experiencialaboral->creado_por = $user_current["id"];
                         $experiencialaboral->fecha_creacion = date("Y-m-d H:i:s");
                         $experiencialaboral->active = true;
                         //al asignarle un objeto genera error, por tal motivo se envia solo el id
                         $experiencialaboral->propuesta = $participante->propuestas->id;
                         $experiencialaboral->usuario_perfil = $participante->usuario_perfil;

                         $post["id"]= null;

                      //  echo "educacionnoformal---->>".json_encode($educacionnoformal);
                        //  echo "post---->>".json_encode($post);
                         if ($experiencialaboral->save($post) === false) {
                                  //  return json_encode($user_current);

                             //Para auditoria en versión de pruebas
                             foreach ($experiencialaboral->getMessages() as $message) {
                                  echo $message;
                                }

                         } else {

                           //echo "guardando archivo";
                           //echo json_encode($_FILES);
                           //Recorro todos los posibles archivos
                           foreach($_FILES as $clave => $valor){
                               $fileTmpPath = $valor['tmp_name'];
                               $fileType = $valor['type'];
                               $fileNameCmps = explode(".", $valor["name"]);
                               $fileExtension = strtolower(end($fileNameCmps));
                              // $fileName = "c".$request->getPost('convocatoria_padre_categoria')."d".$convocatoriaanexo->id."u".$convocatoriaanexo->creado_por."f".date("YmdHis").".".$fileExtension;
                              // $return = $chemistry_alfresco->newFile("/Sites/convocatorias/".$request->getPost('convocatoria_padre_categoria')."/".$request->getPost('anexos')."/", $fileName, file_get_contents($fileTmpPath), $fileType);

                             if($valor['error'] == 0){
                              /*
                              * propuesta[codigo]experiencialaboral[codigo]usuario[codigo]fecha[YmdHis].extension
                              * p(cod)el(cod)u(cod)f(YmdHis).(ext)
                              */
                               $fileName = "p".$experiencialaboral->propuesta."el".$experiencialaboral->id."u".$user_current["id"]."f".date("YmdHis").".".$fileExtension;
                               $filepath = "/Sites/convocatorias/".$request->getPost('idc')."/propuestas/".$experiencialaboral->propuesta;
                               $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);
                              //  echo "    ".json_encode($return);
                               if(strpos($return, "Error") !== FALSE){
                                  //  echo "    ".json_encode($return);
                                   echo "error_creo_alfresco";
                               }else{

                                   $experiencialaboral->file = $return;
                                   if ($experiencialaboral->save() === false) {
                                       echo "error";
                                      //Para auditoria en versión de pruebas
                                      foreach ($experiencialaboral->getMessages() as $message) {
                                           echo $message;
                                         }
                                   }

                               }
                             }else{
                               //echo "error".$valor['error'];
                             }

                           }

                             echo $experiencialaboral->id;
                         }

                       }else{
                         echo "deshabilitado";
                       }

                     }else {
                           return "error";
                       }

            } else {
                return "acceso_denegado";
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        echo "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
    }
}
);

// Edita el registro
$app->post('/edit_experiencia_laboral/{id:[0-9]+}', function ($id) use ($app, $config) {

  try {


        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);


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
                         ->andWhere("Propuestas.convocatoria = ".$request->getPost('idc'))
                         ->execute()
                         ->getFirst();

                         //valido si la propuesta tiene el estado registrada
                       if( $participante->propuestas != null and $participante->propuestas->estado == 7 ){

                           $experiencialaboral = Experiencialaboral::findFirst($id);
                           $experiencialaboral->actualizado_por = $user_current["id"];
                           $experiencialaboral->fecha_actualizacion = date("Y-m-d H:i:s");

                        //  echo "educacionformal---->>".json_encode($educacionformal);
                            //echo "post---->>".json_encode($post);
                           if ($experiencialaboral->save($post) === false) {
                                    //  return json_encode($user_current);
                                     echo "error";
                               //Para auditoria en versión de pruebas
                               foreach ($experiencialaboral->getMessages() as $message) {
                                    echo $message;
                                  }

                           } else {

                             //echo "guardando archivo";
                             //echo json_encode($_FILES);
                             //Recorro todos los posibles archivos
                             foreach($_FILES as $clave => $valor){
                                 $fileTmpPath = $valor['tmp_name'];
                                 $fileType = $valor['type'];
                                 $fileNameCmps = explode(".", $valor["name"]);
                                 $fileExtension = strtolower(end($fileNameCmps));
                                // $fileName = "c".$request->getPost('convocatoria_padre_categoria')."d".$convocatoriaanexo->id."u".$convocatoriaanexo->creado_por."f".date("YmdHis").".".$fileExtension;
                                // $return = $chemistry_alfresco->newFile("/Sites/convocatorias/".$request->getPost('convocatoria_padre_categoria')."/".$request->getPost('anexos')."/", $fileName, file_get_contents($fileTmpPath), $fileType);

                               if($valor['error'] == 0){
                                /*
                                * propuesta[codigo]experiencialaboral[codigo]usuario[codigo]fecha[YmdHis].extension
                                * p(cod)el(cod)u(cod)f(YmdHis).(ext)
                                */
                                 $fileName = "p".$experiencialaboral->propuesta."el".$experiencialaboral->id."u".$user_current["id"]."f".date("YmdHis").".".$fileExtension;
                                 $filepath = "/Sites/convocatorias/".$request->getPost('idc')."/propuestas/".$experiencialaboral->propuesta;
                                 $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);
                                //  echo "    ".json_encode($return);
                                 if(strpos($return, "Error") !== FALSE){
                                    //  echo "    ".json_encode($return);
                                     echo "error_creo_alfresco";
                                 }else{

                                     $experiencialaboral->file = $return;
                                     if ($experiencialaboral->save() === false) {
                                         echo "error";
                                        //Para auditoria en versión de pruebas
                                        foreach ($experiencialaboral->getMessages() as $message) {
                                             echo $message;
                                           }
                                     }

                                 }
                               }else{
                                 //echo "error".$valor['error'];
                               }

                             }

                               return $experiencialaboral->id;
                           }
                       }else{
                         return "deshabilitado";
                       }

                     } else {
                           return "error";
                       }

            } else {
                return "acceso_denegado";
            }
        } else {
          return "error_token";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
    }

}
);

// Eliminar registro de los perfiles de las convocatorias
$app->delete('/delete_experiencia_laboral/{id:[0-9]+}', function ($id) use ($app, $config) {
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
                    $participante = Participantes::query()
                      ->join("Usuariosperfiles","Participantes.usuario_perfil = Usuariosperfiles.id")
                      ->join("Propuestas"," Participantes.id = Propuestas.participante")
                       //perfil = 17  perfil de jurado
                      ->where("Usuariosperfiles.perfil = 17 ")
                      ->andWhere("Usuariosperfiles.usuario = ".$user_current["id"])
                      ->andWhere("Propuestas.convocatoria = ".$request->getPut('idc'))
                      ->execute()
                      ->getFirst();

                      //valido si la propuesta tiene el estado registrada
                    if( $participante->propuestas  != null and $participante->propuestas->estado == 7 ){

                      $experiencialaboral = Experiencialaboral::findFirst($id);

                      if($experiencialaboral->active==true){
                          $experiencialaboral->active=false;
                          $retorna="No";
                      }else{
                          $experiencialaboral->active=true;
                          $retorna="Si";
                      }

                      $experiencialaboral->actualizado_por = $user_current["id"];
                      $experiencialaboral->fecha_actualizacion = date("Y-m-d H:i:s");

                      if ($experiencialaboral->save($post) === false) {
                        //Para auditoria en versión de pruebas
                        foreach ($experiencialaboral->getMessages() as $message) {
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
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
    }
});



//Busca el registro experiencia_laboral
$app->get('/search_experiencia_jurado', function () use ($app, $config) {
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

                             $experienciajurado=Experienciajurado::findFirst( $request->get('idregistro') );

                       }


                        $array["usuario_perfil"]=$usuario_perfil->id;

                         //Ciudades
                         foreach( Ciudades::find("active=true") as $value )
                         {
                             $array_ciudades[]=array("id"=>$value->id,"label"=>$value->nombre." - ".$value->getDepartamentos()->nombre." - ".$value->getDepartamentos()->getPaises()->nombre,"value"=>$value->nombre);

                             if($experienciajurado->ciudad != null && $experienciajurado->ciudad == $value->id ){
                                $array["ciudad_name"]=$value->nombre;
                             }

                         }
                         $array["ciudad"]=$array_ciudades;

                        $ambitos =Categoriajurado::find("active=true AND tipo='jurado_ambito'");
                        $array["ambito"]=array();
                        foreach ( $ambitos as $ambito) {
                          array_push($array["ambito"], ["id"=> $ambito->id, "nombre"=> $ambito->nombre ] );
                        }

                         $array["experienciajurado"] = $experienciajurado;

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


                       $experienciajurados = Experienciajurado::find(
                         [
                           "usuario_perfil= ".$usuario_perfil->id
                           ." AND nombre LIKE '%".$request->get("search")['value']."%'"
                           ." OR entidad LIKE '%".$request->get("search")['value']."%'"
                           ." OR anio LIKE '%".$request->get("search")['value']."%'"
                           ." AND propuesta = ".$participante->propuestas->id,
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
                       $texperienciajurado = Experienciajurado::find([
                         "usuario_perfil = ".$usuario_perfil->id
                       ]);

                     }

            }


            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($texperienciajurado->count()),
                "recordsFiltered" => intval($experienciajurados->count()),
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

// Crea el registro
$app->post('/new_experiencia_jurado', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);


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
                         ->andWhere("Propuestas.convocatoria = ".$request->getPost('idc'))
                         ->execute()
                         ->getFirst();

                       //valido si la propuesta tiene el estado registrada
                     if( $participante->propuestas != null and $participante->propuestas->estado == 7 ){

                         $experienciajurado = new Experienciajurado();
                         $experienciajurado->creado_por = $user_current["id"];
                         $experienciajurado->fecha_creacion = date("Y-m-d H:i:s");
                         $experienciajurado->active = true;
                         //al asignarle un objeto genera error, por tal motivo se envia solo el id
                         $experienciajurado->propuesta = $participante->propuestas->id;
                         $experienciajurado->usuario_perfil = $participante->usuario_perfil;

                         $post["id"]= null;

                        //echo "educacionnoformal---->>".json_encode($experienciajurado);
                          //echo "post---->>".json_encode($post);
                         if ($experienciajurado->save($post) === false) {
                                  //  return json_encode($user_current);

                             //Para auditoria en versión de pruebas
                             foreach ($experienciajurado->getMessages() as $message) {
                                  echo $message;
                                }

                         } else {
                           //echo "guardando archivo";
                           //echo json_encode($_FILES);
                           //Recorro todos los posibles archivos
                           foreach($_FILES as $clave => $valor){
                               $fileTmpPath = $valor['tmp_name'];
                               $fileType = $valor['type'];
                               $fileNameCmps = explode(".", $valor["name"]);
                               $fileExtension = strtolower(end($fileNameCmps));
                              // $fileName = "c".$request->getPost('convocatoria_padre_categoria')."d".$convocatoriaanexo->id."u".$convocatoriaanexo->creado_por."f".date("YmdHis").".".$fileExtension;
                              // $return = $chemistry_alfresco->newFile("/Sites/convocatorias/".$request->getPost('convocatoria_padre_categoria')."/".$request->getPost('anexos')."/", $fileName, file_get_contents($fileTmpPath), $fileType);

                             if($valor['error'] == 0){
                              /*
                              * propuesta[codigo]experienciajurado[codigo]usuario[codigo]fecha[YmdHis].extension
                              * p(cod)ej(cod)u(cod)f(YmdHis).(ext)
                              */
                               $fileName = "p".$experienciajurado->propuesta."ej".$experienciajurado->id."u".$user_current["id"]."f".date("YmdHis").".".$fileExtension;
                               $filepath = "/Sites/convocatorias/".$request->getPost('idc')."/propuestas/".$experienciajurado->propuesta;
                               $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);
                              //  echo "    ".json_encode($return);
                               if(strpos($return, "Error") !== FALSE){
                                  //  echo "    ".json_encode($return);
                                   echo "error_creo_alfresco";
                               }else{

                                   $experienciajurado->file = $return;
                                   if ($experienciajurado->save() === false) {
                                       echo "error";
                                      //Para auditoria en versión de pruebas
                                      foreach ($experienciajurado->getMessages() as $message) {
                                           echo $message;
                                         }
                                   }

                               }
                             }else{
                               //echo "error".$valor['error'];
                             }

                           }

                             echo $experienciajurado->id;
                         }

                       }else{
                         return "deshabilitado";
                       }

                     }else {
                           return "error";
                       }

            } else {
                return "acceso_denegado";
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        echo "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
    }
}
);

// Edita el registro
$app->post('/edit_experiencia_jurado/{id:[0-9]+}', function ($id) use ($app, $config) {

  try {


        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

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
                         ->andWhere("Propuestas.convocatoria = ".$request->getPost('idc'))
                         ->execute()
                         ->getFirst();

                         //valido si la propuesta tiene el estado registrada
                       if( $participante->propuestas != null and $participante->propuestas->estado == 7 ){

                           $experienciajurado = Experienciajurado::findFirst($id);
                           $experienciajurado->actualizado_por = $user_current["id"];
                           $experienciajurado->fecha_actualizacion = date("Y-m-d H:i:s");

                        //  echo "educacionformal---->>".json_encode($educacionformal);
                            //echo "post---->>".json_encode($post);
                           if ($experienciajurado->save($post) === false) {
                                    //  return json_encode($user_current);

                               //Para auditoria en versión de pruebas
                               foreach ($experienciajurado->getMessages() as $message) {
                                    echo $message;
                                  }

                           } else {

                             //echo "guardando archivo";
                             //echo json_encode($_FILES);
                             //Recorro todos los posibles archivos
                             foreach($_FILES as $clave => $valor){
                                 $fileTmpPath = $valor['tmp_name'];
                                 $fileType = $valor['type'];
                                 $fileNameCmps = explode(".", $valor["name"]);
                                 $fileExtension = strtolower(end($fileNameCmps));
                                // $fileName = "c".$request->getPost('convocatoria_padre_categoria')."d".$convocatoriaanexo->id."u".$convocatoriaanexo->creado_por."f".date("YmdHis").".".$fileExtension;
                                // $return = $chemistry_alfresco->newFile("/Sites/convocatorias/".$request->getPost('convocatoria_padre_categoria')."/".$request->getPost('anexos')."/", $fileName, file_get_contents($fileTmpPath), $fileType);

                               if($valor['error'] == 0){
                                /*
                                * propuesta[codigo]experienciajurado[codigo]usuario[codigo]fecha[YmdHis].extension
                                * p(cod)ej(cod)u(cod)f(YmdHis).(ext)
                                */
                                 $fileName = "p".$experienciajurado->propuesta."ej".$experienciajurado->id."u".$user_current["id"]."f".date("YmdHis").".".$fileExtension;
                                 $filepath = "/Sites/convocatorias/".$request->getPost('idc')."/propuestas/".$experienciajurado->propuesta;
                                 $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);
                                //  echo "    ".json_encode($return);
                                 if(strpos($return, "Error") !== FALSE){
                                    //  echo "    ".json_encode($return);
                                     echo "error_creo_alfresco";
                                 }else{

                                     $experienciajurado->file = $return;
                                     if ($experienciajurado->save() === false) {
                                         echo "error";
                                        //Para auditoria en versión de pruebas
                                        foreach ($experienciajurado->getMessages() as $message) {
                                             echo $message;
                                           }
                                     }

                                 }
                               }else{
                                 //echo "error".$valor['error'];
                               }

                             }

                               return $experienciajurado->id;
                           }
                       }else{
                         return "deshabilitado";
                       }

                     } else {
                           return "error";
                       }

            } else {
                return "acceso_denegado";
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
    }

}
);

// Eliminar registro
$app->delete('/delete_experiencia_jurado/{id:[0-9]+}', function ($id) use ($app, $config) {
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

                    $participante = Participantes::query()
                      ->join("Usuariosperfiles","Participantes.usuario_perfil = Usuariosperfiles.id")
                      ->join("Propuestas"," Participantes.id = Propuestas.participante")
                       //perfil = 17  perfil de jurado
                      ->where("Usuariosperfiles.perfil = 17 ")
                      ->andWhere("Usuariosperfiles.usuario = ".$user_current["id"])
                      ->andWhere("Propuestas.convocatoria = ".$request->getPut('idc'))
                      ->execute()
                      ->getFirst();

                      //valido si la propuesta tiene el estado registrada
                    if( $participante->propuestas != null and $participante->propuestas->estado == 7 ){

                      $experienciajurado = Experienciajurado::findFirst($id);

                      if($experienciajurado->active==true){
                          $experienciajurado->active=false;
                          $retorna="No";
                      }else{
                          $experienciajurado->active=true;
                          $retorna="Si";
                      }

                      $experienciajurado->actualizado_por = $user_current["id"];
                      $experienciajurado->fecha_actualizacion = date("Y-m-d H:i:s");

                      if ($experienciajurado->save($post) === false) {
                        //Para auditoria en versión de pruebas
                        foreach ($experienciajurado->getMessages() as $message) {
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
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
    }
});



//Busca el registro experiencia_laboral
$app->get('/search_reconocimiento', function () use ($app, $config) {
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
                             $reconocimiento=Propuestajuradoreconocimiento::findFirst( $request->get('idregistro') );
                       }


                        $array["usuario_perfil"]=$usuario_perfil->id;

                         //Ciudades
                         foreach( Ciudades::find("active=true") as $value )
                         {
                             $array_ciudades[]=array("id"=>$value->id,"label"=>$value->nombre." - ".$value->getDepartamentos()->nombre." - ".$value->getDepartamentos()->getPaises()->nombre,"value"=>$value->nombre);

                             if($reconocimiento->ciudad != null && $reconocimiento->ciudad == $value->id ){
                                $array["ciudad_name"]=$value->nombre;
                             }

                         }
                         $array["ciudad"]=$array_ciudades;

                        $tipos =Categoriajurado::find("active=true AND tipo='reconocimiento_tipo'");
                        $array["tipo"]=array();
                        foreach ( $tipos as $tipo) {
                          array_push($array["tipo"], ["id"=> $tipo->id, "nombre"=> $tipo->nombre ] );
                        }

                         $array["reconocimiento"] = $reconocimiento;

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


                       $reconocimientos = Propuestajuradoreconocimiento::find(
                         [
                           "usuario_perfil= ".$usuario_perfil->id
                           ." AND nombre LIKE '%".$request->get("search")['value']."%'"
                           ." OR institucion LIKE '%".$request->get("search")['value']."%'"
                           ." OR anio LIKE '%".$request->get("search")['value']."%'"
                           ." AND propuesta = ".$participante->propuestas->id,
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
                       $treconocimiento = Propuestajuradoreconocimiento::find([
                         "usuario_perfil = ".$usuario_perfil->id
                       ]);

                     }

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

// Crea el registro
$app->post('/new_reconocimiento', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);


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
                         ->andWhere("Propuestas.convocatoria = ".$request->getPost('idc'))
                         ->execute()
                         ->getFirst();

                       //valido si la propuesta tiene el estado registrada
                     if( $participante->propuestas != null and $participante->propuestas->estado == 7 ){

                         $reconocimiento = new Propuestajuradoreconocimiento();
                         $reconocimiento->creado_por = $user_current["id"];
                         $reconocimiento->fecha_creacion = date("Y-m-d H:i:s");
                         $reconocimiento->active = true;
                         //al asignarle un objeto genera error, por tal motivo se envia solo el id
                         $reconocimiento->propuesta = $participante->propuestas->id;
                         $reconocimiento->usuario_perfil = $participante->usuario_perfil;

                         $post["id"]= null;

                        //echo "educacionnoformal---->>".json_encode($reconocimiento);
                        //  echo "post---->>".json_encode($post);
                         if ($reconocimiento->save($post) === false) {
                                  //  return json_encode($user_current);

                             //Para auditoria en versión de pruebas
                             foreach ($reconocimiento->getMessages() as $message) {
                                  echo $message;
                                }

                         } else {

                           //echo "guardando archivo";
                           //echo json_encode($_FILES);
                           //Recorro todos los posibles archivos
                           foreach($_FILES as $clave => $valor){
                               $fileTmpPath = $valor['tmp_name'];
                               $fileType = $valor['type'];
                               $fileNameCmps = explode(".", $valor["name"]);
                               $fileExtension = strtolower(end($fileNameCmps));
                              // $fileName = "c".$request->getPost('convocatoria_padre_categoria')."d".$convocatoriaanexo->id."u".$convocatoriaanexo->creado_por."f".date("YmdHis").".".$fileExtension;
                              // $return = $chemistry_alfresco->newFile("/Sites/convocatorias/".$request->getPost('convocatoria_padre_categoria')."/".$request->getPost('anexos')."/", $fileName, file_get_contents($fileTmpPath), $fileType);

                             if($valor['error'] == 0){
                              /*
                              * propuesta[codigo]reconocimiento[codigo]usuario[codigo]fecha[YmdHis].extension
                              * p(cod)rj(cod)u(cod)f(YmdHis).(ext)
                              */
                               $fileName = "p".$reconocimiento->propuesta."rj".$reconocimiento->id."u".$user_current["id"]."f".date("YmdHis").".".$fileExtension;
                               $filepath = "/Sites/convocatorias/".$request->getPost('idc')."/propuestas/".$reconocimiento->propuesta;
                               $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);
                              //  echo "    ".json_encode($return);
                               if(strpos($return, "Error") !== FALSE){
                                  //  echo "    ".json_encode($return);
                                   echo "error_creo_alfresco";
                               }else{

                                   $reconocimiento->file = $return;
                                   if ($reconocimiento->save() === false) {
                                       echo "error";
                                      //Para auditoria en versión de pruebas
                                      foreach ($reconocimiento->getMessages() as $message) {
                                           echo $message;
                                         }
                                   }

                               }
                             }else{
                               //echo "error".$valor['error'];
                             }

                           }

                             echo $reconocimiento->id;
                         }

                       }else{
                         return "deshabilitado";
                       }

                     }else {
                           return "error";
                       }

            } else {
                return "acceso_denegado";
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        echo "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
    }
}
);

// Edita el registro
$app->post('/edit_reconocimiento/{id:[0-9]+}', function ($id) use ($app, $config) {

  try {


        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);


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
                         ->andWhere("Propuestas.convocatoria = ".$request->getPost('idc'))
                         ->execute()
                         ->getFirst();

                         //valido si la propuesta tiene el estado registrada
                       if( $participante->propuestas != null and $participante->propuestas->estado == 7 ){

                           $reconocimiento= Propuestajuradoreconocimiento::findFirst($id);
                           $reconocimiento->actualizado_por = $user_current["id"];
                           $reconocimiento->fecha_actualizacion = date("Y-m-d H:i:s");

                        //  echo "educacionformal---->>".json_encode($educacionformal);
                            //echo "post---->>".json_encode($post);
                           if ($reconocimiento->save($post) === false) {
                                    //  return json_encode($user_current);

                               //Para auditoria en versión de pruebas
                               foreach ($reconocimiento->getMessages() as $message) {
                                    echo $message;
                                  }

                           } else {

                             //echo "guardando archivo";
                             //echo json_encode($_FILES);
                             //Recorro todos los posibles archivos
                             foreach($_FILES as $clave => $valor){
                                 $fileTmpPath = $valor['tmp_name'];
                                 $fileType = $valor['type'];
                                 $fileNameCmps = explode(".", $valor["name"]);
                                 $fileExtension = strtolower(end($fileNameCmps));
                                // $fileName = "c".$request->getPost('convocatoria_padre_categoria')."d".$convocatoriaanexo->id."u".$convocatoriaanexo->creado_por."f".date("YmdHis").".".$fileExtension;
                                // $return = $chemistry_alfresco->newFile("/Sites/convocatorias/".$request->getPost('convocatoria_padre_categoria')."/".$request->getPost('anexos')."/", $fileName, file_get_contents($fileTmpPath), $fileType);

                               if($valor['error'] == 0){
                                /*
                                * propuesta[codigo]reconocimiento[codigo]usuario[codigo]fecha[YmdHis].extension
                                * p(cod)rj(cod)u(cod)f(YmdHis).(ext)
                                */
                                 $fileName = "p".$reconocimiento->propuesta."rj".$reconocimiento->id."u".$user_current["id"]."f".date("YmdHis").".".$fileExtension;
                                 $filepath = "/Sites/convocatorias/".$request->getPost('idc')."/propuestas/".$reconocimiento->propuesta;
                                 $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);
                                //  echo "    ".json_encode($return);
                                 if(strpos($return, "Error") !== FALSE){
                                    //  echo "    ".json_encode($return);
                                     echo "error_creo_alfresco";
                                 }else{

                                     $reconocimiento->file = $return;
                                     if ($reconocimiento->save() === false) {
                                         echo "error";
                                        //Para auditoria en versión de pruebas
                                        foreach ($reconocimiento->getMessages() as $message) {
                                             echo $message;
                                           }
                                     }

                                 }
                               }else{
                                 //echo "error".$valor['error'];
                               }

                             }

                               return $reconocimiento->id;
                           }
                       }else{
                         return "deshabilitado";
                       }

                     } else {
                           return "error";
                       }

            } else {
                return "acceso_denegado";
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
    }

}
);

// Eliminar registro
$app->delete('/delete_reconocimiento/{id:[0-9]+}', function ($id) use ($app, $config) {
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


                    $participante = Participantes::query()
                      ->join("Usuariosperfiles","Participantes.usuario_perfil = Usuariosperfiles.id")
                      ->join("Propuestas"," Participantes.id = Propuestas.participante")
                       //perfil = 17  perfil de jurado
                      ->where("Usuariosperfiles.perfil = 17 ")
                      ->andWhere("Usuariosperfiles.usuario = ".$user_current["id"])
                      ->andWhere("Propuestas.convocatoria = ".$$request->getPut('idc'))
                      ->execute()
                      ->getFirst();

                      //valido si la propuesta tiene el estado registrada
                    if( $participante->propuestas != null and $participante->propuestas->estado == 7 ){

                      $reconocimiento = Propuestajuradoreconocimiento::findFirst($id);

                      if($reconocimiento->active==true){
                          $reconocimiento->active=false;
                          $retorna="No";
                      }else{
                          $reconocimiento->active=true;
                          $retorna="Si";
                      }

                      $reconocimiento->actualizado_por = $user_current["id"];
                      $reconocimiento->fecha_actualizacion = date("Y-m-d H:i:s");

                      if ($reconocimiento->save($post) === false) {
                        //Para auditoria en versión de pruebas
                        foreach ($reconocimiento->getMessages() as $message) {
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
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
    }
});



//Busca el registro experiencia_laboral
$app->get('/search_publicacion', function () use ($app, $config) {
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
                             $publicacion=Propuestajuradopublicacion::findFirst( $request->get('idregistro') );
                       }


                        $array["usuario_perfil"]=$usuario_perfil->id;

                         //Ciudades
                         foreach( Ciudades::find("active=true") as $value )
                         {
                             $array_ciudades[]=array("id"=>$value->id,"label"=>$value->nombre." - ".$value->getDepartamentos()->nombre." - ".$value->getDepartamentos()->getPaises()->nombre,"value"=>$value->nombre);

                             if($publicacion->ciudad != null && $publicacion->ciudad == $value->id ){
                                $array["ciudad_name"]=$value->nombre;
                             }

                         }
                         $array["ciudad"]=$array_ciudades;

                        $tipos =Categoriajurado::find("active=true AND tipo='publicaciones_tipo'");
                        $array["tipo"]=array();
                        foreach ( $tipos as $tipo) {
                          array_push($array["tipo"], ["id"=> $tipo->id, "nombre"=> $tipo->nombre ] );
                        }

                        $formatos =Categoriajurado::find("active=true AND tipo='publicaciones_formato'");
                        $array["formato"]=array();
                        foreach ( $formatos as $formato) {
                          array_push($array["formato"], ["id"=> $formato->id, "nombre"=> $formato->nombre ] );
                        }

                         $array["publicacion"] = $publicacion;

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


                       $publicaciones = Propuestajuradopublicacion::find(
                         [
                           "usuario_perfil= ".$usuario_perfil->id
                           ." AND titulo LIKE '%".$request->get("search")['value']."%'"
                           ." OR tema LIKE '%".$request->get("search")['value']."%'"
                           ." OR anio LIKE '%".$request->get("search")['value']."%'"
                           ." AND propuesta = ".$participante->propuestas->id,
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
                       $tpublicacion = Propuestajuradopublicacion::find([
                         "usuario_perfil = ".$usuario_perfil->id
                       ]);

                     }

            }


            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval( $tpublicacion ->count()),
                "recordsFiltered" => intval($publicaciones->count()),
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

// Crea el registro
$app->post('/new_publicacion', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);


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
                         ->andWhere("Propuestas.convocatoria = ".$request->getPost('idc'))
                         ->execute()
                         ->getFirst();

                       //valido si la propuesta tiene el estado registrada
                     if( $participante->propuestas != null and $participante->propuestas->estado == 7 ){

                         $publicacion = new Propuestajuradopublicacion();
                         $publicacion->creado_por = $user_current["id"];
                         $publicacion->fecha_creacion = date("Y-m-d H:i:s");
                         $publicacion->active = true;
                         //al asignarle un objeto genera error, por tal motivo se envia solo el id
                         $publicacion->propuesta = $participante->propuestas->id;
                         $publicacion->usuario_perfil = $participante->usuario_perfil;

                         $post["id"]= null;

                        //echo "educacionnoformal---->>".json_encode($publicacion);
                          //echo "post---->>".json_encode($post);
                         if ($publicacion->save($post) === false) {
                                  //  return json_encode($user_current);

                             //Para auditoria en versión de pruebas
                             foreach ($publicacion->getMessages() as $message) {
                                  echo $message;
                                }

                         } else {

                           //echo "guardando archivo";
                           //echo json_encode($_FILES);
                           //Recorro todos los posibles archivos
                           foreach($_FILES as $clave => $valor){
                               $fileTmpPath = $valor['tmp_name'];
                               $fileType = $valor['type'];
                               $fileNameCmps = explode(".", $valor["name"]);
                               $fileExtension = strtolower(end($fileNameCmps));
                              // $fileName = "c".$request->getPost('convocatoria_padre_categoria')."d".$convocatoriaanexo->id."u".$convocatoriaanexo->creado_por."f".date("YmdHis").".".$fileExtension;
                              // $return = $chemistry_alfresco->newFile("/Sites/convocatorias/".$request->getPost('convocatoria_padre_categoria')."/".$request->getPost('anexos')."/", $fileName, file_get_contents($fileTmpPath), $fileType);

                             if($valor['error'] == 0){
                              /*
                              * propuesta[codigo]publicacion[codigo]usuario[codigo]fecha[YmdHis].extension
                              * p(cod)pj(cod)u(cod)f(YmdHis).(ext)
                              */
                               $fileName = "p".$publicacion->propuesta."pj".$publicacion->id."u".$user_current["id"]."f".date("YmdHis").".".$fileExtension;
                               $filepath = "/Sites/convocatorias/".$request->getPost('idc')."/propuestas/".$publicacion->propuesta;
                               $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);
                              //  echo "    ".json_encode($return);
                               if(strpos($return, "Error") !== FALSE){
                                  //  echo "    ".json_encode($return);
                                   echo "error_creo_alfresco";
                               }else{

                                   $publicacion->file = $return;
                                   if ($publicacion->save() === false) {
                                       echo "error";
                                      //Para auditoria en versión de pruebas
                                      foreach ($publicacion->getMessages() as $message) {
                                           echo $message;
                                         }
                                   }

                               }
                             }else{
                               //echo "error".$valor['error'];
                             }

                           }


                             echo $publicacion->id;
                         }

                       }else{
                         return "deshabilitado";
                       }

                     }else {
                           return "error";
                       }

            } else {
                return "acceso_denegado";
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        echo "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
    }
}
);

// Edita el registro
$app->post('/edit_publicacion/{id:[0-9]+}', function ($id) use ($app, $config) {

  try {


        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);


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
                         ->andWhere("Propuestas.convocatoria = ".$request->getPost('idc'))
                         ->execute()
                         ->getFirst();

                         //valido si la propuesta tiene el estado registrada
                       if( $participante->propuestas != null and $participante->propuestas->estado == 7 ){

                           $publicacion= Propuestajuradopublicacion::findFirst($id);
                           $publicacion->actualizado_por = $user_current["id"];
                           $publicacion->fecha_actualizacion = date("Y-m-d H:i:s");

                        //  echo "educacionformal---->>".json_encode($educacionformal);
                            //echo "post---->>".json_encode($post);
                           if ($publicacion->save($post) === false) {
                                    //  return json_encode($user_current);

                               //Para auditoria en versión de pruebas
                               foreach ($publicacion->getMessages() as $message) {
                                    echo $message;
                                  }

                           } else {


                                //echo "guardando archivo";
                                //echo json_encode($_FILES);
                                //Recorro todos los posibles archivos
                                foreach($_FILES as $clave => $valor){
                                    $fileTmpPath = $valor['tmp_name'];
                                    $fileType = $valor['type'];
                                    $fileNameCmps = explode(".", $valor["name"]);
                                    $fileExtension = strtolower(end($fileNameCmps));
                                   // $fileName = "c".$request->getPost('convocatoria_padre_categoria')."d".$convocatoriaanexo->id."u".$convocatoriaanexo->creado_por."f".date("YmdHis").".".$fileExtension;
                                   // $return = $chemistry_alfresco->newFile("/Sites/convocatorias/".$request->getPost('convocatoria_padre_categoria')."/".$request->getPost('anexos')."/", $fileName, file_get_contents($fileTmpPath), $fileType);

                                  if($valor['error'] == 0){
                                   /*
                                   * propuesta[codigo]publicacion[codigo]usuario[codigo]fecha[YmdHis].extension
                                   * p(cod)pj(cod)u(cod)f(YmdHis).(ext)
                                   */
                                    $fileName = "p".$publicacion->propuesta."pj".$publicacion->id."u".$user_current["id"]."f".date("YmdHis").".".$fileExtension;
                                    $filepath = "/Sites/convocatorias/".$request->getPost('idc')."/propuestas/".$publicacion->propuesta;
                                    $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);
                                   //  echo "    ".json_encode($return);
                                    if(strpos($return, "Error") !== FALSE){
                                       //  echo "    ".json_encode($return);
                                        echo "error_creo_alfresco";
                                    }else{

                                        $publicacion->file = $return;
                                        if ($publicacion->save() === false) {
                                            echo "error";
                                           //Para auditoria en versión de pruebas
                                           foreach ($publicacion->getMessages() as $message) {
                                                echo $message;
                                              }
                                        }

                                    }
                                  }else{
                                    //echo "error".$valor['error'];
                                  }

                                }

                               return $publicacion->id;
                           }
                       }else{
                         return "deshabilitado";
                       }

                     } else {
                           return "error";
                       }

            } else {
                return "acceso_denegado";
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
    }

}
);

// Eliminar registro
$app->delete('/delete_publicacion/{id:[0-9]+}', function ($id) use ($app, $config) {
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

                    $participante = Participantes::query()
                      ->join("Usuariosperfiles","Participantes.usuario_perfil = Usuariosperfiles.id")
                      ->join("Propuestas"," Participantes.id = Propuestas.participante")
                       //perfil = 17  perfil de jurado
                      ->where("Usuariosperfiles.perfil = 17 ")
                      ->andWhere("Usuariosperfiles.usuario = ".$user_current["id"])
                      ->andWhere("Propuestas.convocatoria = ".$request->getPut('idc'))
                      ->execute()
                      ->getFirst();

                      //valido si la propuesta tiene el estado registrada
                    if( $participante->propuestas != null and $participante->propuestas->estado == 7 ){

                      $publicacion = Propuestajuradopublicacion::findFirst($id);

                      if($publicacion->active==true){
                          $publicacion->active=false;
                          $retorna="No";
                      }else{
                          $publicacion->active=true;
                          $retorna="Si";
                      }

                      $publicacion->actualizado_por = $user_current["id"];
                      $publicacion->fecha_actualizacion = date("Y-m-d H:i:s");

                      if ($publicacion->save($post) === false) {
                        //Para auditoria en versión de pruebas
                        foreach ($publicacion->getMessages() as $message) {
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
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
    }
});

//descargar archivos
$app->post('/download_file', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo
        if ($token_actual > 0) {
            echo $chemistry_alfresco->download($request->getPost('cod'));
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
      //  echo "error_metodo";

        return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
    }
}
);



// Accion de postular la hoja de vida del perfil jurado
$app->get('/postular', function () use ($app, $config) {

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
                //Consulto el usuario actual
                $post = $app->request->get();

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

                         //valido si la propuesta tiene el estado registrada
                       if( $participante->propuestas != null and $participante->propuestas->estado == 7 ){

                           $participante->propuestas->estado = 8; //inscrita
                           $participante->propuestas->actualizado_por = $user_current["id"];
                           $participante->propuestas->fecha_actualizacion = date("Y-m-d H:i:s");

                        //  echo "educacionformal---->>".json_encode($educacionformal);
                            //echo "post---->>".json_encode($post);
                           if ($participante->propuestas->save() === false) {
                                    //  return json_encode($user_current);

                               //Para auditoria en versión de pruebas
                               foreach ($participante->propuestas->getMessages() as $message) {
                                    echo $message;
                                  }

                           } else {

                               echo $participante->propuestas->id;
                           }
                       }else{
                         return "deshabilitado";
                       }

                     } else {
                           return "error";
                       }

            } else {
                return "acceso_denegado";
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
    }

}
);

// lista la propuesta asociada a la convocatoria
$app->get('/propuesta', function () use ($app, $config) {

  try {

      //Instancio los objetos que se van a manejar
      $request = new Request();
      $tokens = new Tokens();
      $array =  array();


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
              //Consulto el usuario actual
              $post = $app->request->get();

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

                       $array["propuesta"] = $participante->propuestas;

                       $documento = Convocatoriasanexos::findFirst(
                         [
                           "tipo_documento = 'Anexo' AND nombre = 'Condiciones de participación'"
                           ."AND convocatoria = ".$request->get('idc')
                         ]
                       );

                       $array["documento"] = $documento;

                       //echo json_encode($participante->propuestas);
                      echo json_encode( $array );

                   } else {
                         return "error";
                     }

          } else {
              return "acceso_denegado";
          }
      } else {
          return "error_token";
      }

    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
    }

}
);


//Busca el registro experiencia_laboral
$app->get('/search_documento', function () use ($app, $config) {
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
                             $documento=Propuestajuradodocumento::findFirst( $request->get('idregistro') );
                       }


                        $array["usuario_perfil"]=$usuario_perfil->id;


                        $tipos =Categoriajurado::find("active=true AND tipo='anexo'");
                        $array["tipo"]=array();
                        foreach ( $tipos as $tipo) {
                          array_push($array["tipo"], ["id"=> $tipo->id, "nombre"=> $tipo->nombre ] );
                        }

                        $array["documento"] = $documento;

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

        //echo "error_metodo";

      //Para auditoria en versión de pruebas
      echo "error_metodo" . $ex->getMessage();
    }
}
);

//Busca los registros de educacion formal
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


                       $documentos = Propuestajuradodocumento::find(
                         [
                           "usuario_perfil= ".$usuario_perfil->id
                           ." AND propuesta = ".$participante->propuestas->id,
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
                         "usuario_perfil = ".$usuario_perfil->id
                       ]);

                     }

            }


            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval( $tdocumento ->count()),
                "recordsFiltered" => intval($documentos->count()),
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

// Crea el registro
$app->post('/new_documento', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);


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
                         ->andWhere("Propuestas.convocatoria = ".$request->getPost('idc'))
                         ->execute()
                         ->getFirst();

                       //valido si la propuesta tiene el estado registrada
                     if( $participante->propuestas != null and $participante->propuestas->estado == 7 ){

                         $documento = new Propuestajuradodocumento();
                         $documento->creado_por = $user_current["id"];
                         $documento->fecha_creacion = date("Y-m-d H:i:s");
                         $documento->active = true;
                         //al asignarle un objeto genera error, por tal motivo se envia solo el id
                         $documento->propuesta = $participante->propuestas->id;
                         $documento->usuario_perfil = $participante->usuario_perfil;

                         $post["id"]= null;

                        //echo "educacionnoformal---->>".json_encode($publicacion);
                          //echo "post---->>".json_encode($post);
                         if ($documento->save($post) === false) {
                                  //  return json_encode($user_current);

                             //Para auditoria en versión de pruebas
                             foreach ($documento->getMessages() as $message) {
                                  echo $message;
                                }

                         } else {

                           //echo "guardando archivo";
                           //echo json_encode($_FILES);
                           //Recorro todos los posibles archivos
                           foreach($_FILES as $clave => $valor){
                               $fileTmpPath = $valor['tmp_name'];
                               $fileType = $valor['type'];
                               $fileNameCmps = explode(".", $valor["name"]);
                               $fileExtension = strtolower(end($fileNameCmps));
                              // $fileName = "c".$request->getPost('convocatoria_padre_categoria')."d".$convocatoriaanexo->id."u".$convocatoriaanexo->creado_por."f".date("YmdHis").".".$fileExtension;
                              // $return = $chemistry_alfresco->newFile("/Sites/convocatorias/".$request->getPost('convocatoria_padre_categoria')."/".$request->getPost('anexos')."/", $fileName, file_get_contents($fileTmpPath), $fileType);

                             if($valor['error'] == 0){
                              /*
                              * propuesta[codigo]documento[codigo]usuario[codigo]fecha[YmdHis].extension
                              * p(cod)dj(cod)u(cod)f(YmdHis).(ext)
                              */
                               $fileName = "p".$documento->propuesta."dj".$documento->id."u".$user_current["id"]."f".date("YmdHis").".".$fileExtension;
                               $filepath = "/Sites/convocatorias/".$request->getPost('idc')."/propuestas/".$documento->propuesta;
                               $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);
                              //  echo "    ".json_encode($return);
                               if(strpos($return, "Error") !== FALSE){
                                  //  echo "    ".json_encode($return);
                                   echo "error_creo_alfresco";
                               }else{

                                   $documento->file = $return;
                                   if ($documento->save() === false) {
                                       echo "error";
                                      //Para auditoria en versión de pruebas
                                      foreach ($documento->getMessages() as $message) {
                                           echo $message;
                                         }
                                   }

                               }
                             }else{
                               //echo "error".$valor['error'];
                             }

                           }


                             echo $documento->id;
                         }

                       }else{
                         return "deshabilitado";
                       }

                     }else {
                           return "error";
                       }

            } else {
                return "acceso_denegado";
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        echo "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
    }
}
);

// Edita el registro
$app->post('/edit_documento/{id:[0-9]+}', function ($id) use ($app, $config) {

  try {


        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);


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
                         ->andWhere("Propuestas.convocatoria = ".$request->getPost('idc'))
                         ->execute()
                         ->getFirst();

                         //valido si la propuesta tiene el estado registrada
                       if( $participante->propuestas != null and $participante->propuestas->estado == 7 ){

                           $documento= Propuestajuradodocumento::findFirst($id);
                           $documento->actualizado_por = $user_current["id"];
                           $documento->fecha_actualizacion = date("Y-m-d H:i:s");

                          //echo "educacionformal---->>".json_encode($documento);
                            //echo "post---->>".json_encode($post);
                           if ($documento->save($post) === false) {
                                    //  return json_encode($user_current);

                               //Para auditoria en versión de pruebas
                               foreach ($documento->getMessages() as $message) {
                                    echo $message;
                                  }

                           } else {


                                //echo "guardando archivo";
                                //echo json_encode($_FILES);
                                //Recorro todos los posibles archivos
                                foreach($_FILES as $clave => $valor){
                                    $fileTmpPath = $valor['tmp_name'];
                                    $fileType = $valor['type'];
                                    $fileNameCmps = explode(".", $valor["name"]);
                                    $fileExtension = strtolower(end($fileNameCmps));
                                   // $fileName = "c".$request->getPost('convocatoria_padre_categoria')."d".$convocatoriaanexo->id."u".$convocatoriaanexo->creado_por."f".date("YmdHis").".".$fileExtension;
                                   // $return = $chemistry_alfresco->newFile("/Sites/convocatorias/".$request->getPost('convocatoria_padre_categoria')."/".$request->getPost('anexos')."/", $fileName, file_get_contents($fileTmpPath), $fileType);

                                  if($valor['error'] == 0){
                                   /*
                                   * propuesta[codigo]documento[codigo]usuario[codigo]fecha[YmdHis].extension
                                   * p(cod)dj(cod)u(cod)f(YmdHis).(ext)
                                   */
                                    $fileName = "p".$documento->propuesta."dj".$documento->id."u".$user_current["id"]."f".date("YmdHis").".".$fileExtension;
                                    $filepath = "/Sites/convocatorias/".$request->getPost('idc')."/propuestas/".$documento->propuesta;
                                    $return = $chemistry_alfresco->newFile($filepath, $fileName, file_get_contents($fileTmpPath), $fileType);
                                     echo "    ".json_encode($return);
                                    if(strpos($return, "Error") !== FALSE){
                                         echo "    ".json_encode($return);
                                        echo "error_creo_alfresco";
                                    }else{

                                        $documento->file = $return;
                                        if ($documento->save() === false) {
                                            echo "error";
                                           //Para auditoria en versión de pruebas
                                           foreach ($documento->getMessages() as $message) {
                                                echo $message;
                                              }
                                        }

                                    }
                                  }else{
                                    //echo "error".$valor['error'];
                                  }

                                }

                               return $documento->id;
                           }
                       }else{
                         return "deshabilitado";
                       }

                     } else {
                           return "error";
                       }

            } else {
                return "acceso_denegado";
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
    }

}
);

// Eliminar registro
$app->delete('/delete_documento/{id:[0-9]+}', function ($id) use ($app, $config) {
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

                    $participante = Participantes::query()
                      ->join("Usuariosperfiles","Participantes.usuario_perfil = Usuariosperfiles.id")
                      ->join("Propuestas"," Participantes.id = Propuestas.participante")
                       //perfil = 17  perfil de jurado
                      ->where("Usuariosperfiles.perfil = 17 ")
                      ->andWhere("Usuariosperfiles.usuario = ".$user_current["id"])
                      ->andWhere("Propuestas.convocatoria = ".$request->getPut('idc'))
                      ->execute()
                      ->getFirst();

                      //valido si la propuesta tiene el estado registrada
                    if( $participante->propuestas != null and $participante->propuestas->estado == 7 ){

                      $documento = Propuestajuradodocumento::findFirst($id);

                      if($documento->active==true){
                          $documento->active=false;
                          $retorna="No";
                      }else{
                          $documento->active=true;
                          $retorna="Si";
                      }

                      $documento->actualizado_por = $user_current["id"];
                      $documento->fecha_actualizacion = date("Y-m-d H:i:s");

                      if ($documento->save($post) === false) {
                        //Para auditoria en versión de pruebas
                        foreach ($documento->getMessages() as $message) {
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
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
    }
});






/*Retorna información de id y nombre del area */
$app->get('/postulacion_select_area', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $categorias=  array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual != false ) {


                //Se construye un array con la información de id y nombre de cada convocatoria para establece rel componente select
              //foreach ( $rs as $key => $value) {
                //      $nucleosbasicos[$key]= array("id"=>$value->id, "nombre"=>$value->nombre);
                //}


                $areas=array();
                foreach ( Areas::find("active=true") as $area) {
                  array_push($areas, ["id"=> $area->id, "nombre"=> $area->nombre ] );
                }

            echo json_encode($areas);
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        return "error_metodo".$ex->getMessage();
    }
}
);

//Busca los registros de educacion formal
$app->get('/postulacion_search_convocatorias', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $id_convocatorias_postuladas = array(0);

        //  $fecha_actual = date("d-m-Y");
         $fecha_actual =  date("Y-m-d H:i:s");

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

                      $postulaciones = $participante->propuestas->juradospostulados;

                      foreach ($postulaciones as $postulacion) {

                           array_push($id_convocatorias_postuladas,$postulacion->convocatorias->id);
                      }




                       $convocatorias = Convocatorias::find(
                         [
                           "id NOT IN ({idConvocatoria:array}) "
                           ." AND area = ".$request->get('area')
                           ." AND ( nombre LIKE '%".$request->get("search")['value']."%'"
                           ." OR descripcion LIKE '%".$request->get("search")['value']."%') "
                           ." AND estado = 5 " //estado publicada
                           ." AND active = true " //activa
                           ." AND modalidad != 2 ", //jurados
                           "order" => 'id ASC',
                           "limit" =>  $request->get('length'),
                           "offset" =>  $request->get('start'),
                           "bind" => [
                             "idConvocatoria" => $id_convocatorias_postuladas
                           ],
                         ]
                       );

                      //  echo json_encode($convocatorias);

                       foreach ($convocatorias as $convocatoria) {

                         $cronograma = Convocatoriascronogramas::findFirst(
                           [
                             "convocatoria = ".$convocatoria->id
                             ." AND descripcion = 'CIERRE'"
                             ." AND active = true"
                           ]);

                           //Agrega la convocatoria si la fecha de cierre tiene mas de 48 horas (2 dias)
                         if ( strtotime($cronograma->fecha_fin) >= strtotime(date("Y-m-d H:i:s",strtotime($fecha_actual."+ 2 days") ) ) ){

                             $area =  Areas::findFirst(
                               ["id=".$convocatoria->area]
                             );
                             $convocatoria->area = $area->nombre;

                             $lineaestrategica =  Lineasestrategicas::findFirst(
                               ["id=".$convocatoria->linea_estrategica]
                             );
                             $convocatoria->linea_estrategica = $lineaestrategica->nombre;

                             $programa =  Programas::findFirst(
                               ["id=".$convocatoria->programa]
                             );
                             $convocatoria->programa = $programa->nombre;

                             $entidad =  Entidades::findFirst(
                               ["id=".$convocatoria->entidad]
                             );
                             $convocatoria->entidad = $entidad->nombre;

                             $enfoque =  Enfoques::findFirst(
                               ["id=".$convocatoria->enfoque]
                             );
                             $convocatoria->enfoque = $enfoque->nombre;


                             $convocatoria->creado_por = null;
                             $convocatoria->actualizado_por = null;

                             array_push($response,$convocatoria);
                           }
                       }

                       //resultado sin filtro
                       $tconvocatorias = Convocatorias::find(
                         [
                           "id NOT IN ({idConvocatoria:array}) " //las postuladas
                           ." AND area = ".$request->get('area')
                           ." AND estado = 5 " //estado publicada
                           ." AND active = true " //activa
                           ." AND modalidad != 2 ", //jurados
                           "bind" => [
                             "idConvocatoria" => $id_convocatorias_postuladas
                           ],
                         ]
                       );

                     }

            }


            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($tconvocatorias->count()),
                "recordsFiltered" => ( $request->get("search")['value'] != null ? intval($convocatorias->count()) : intval($tconvocatorias->count()) ),
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

//Busca los registros de convocatorias
$app->get('/postulacion_perfiles_convocatoria', function () use ($app, $config) {
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

                         $perfiles = Convocatoriasparticipantes::find(
                         [
                           "convocatoria = ".$request->get('idregistro')
                           ." AND tipo_participante = 4 " //jurados
                            ." AND active = true ", //jurados
                           "order" => 'id ASC',
                           "limit" =>  $request->get('length'),
                           "offset" =>  $request->get('start'),
                         ]
                       );

                      //  echo json_encode($convocatorias);

                       foreach ($perfiles as $perfil) {
                         $perfil->creado_por = null;
                         $perfil->fecha_creacion = null;
                         $perfil->actualizado_por = null;
                         $perfil->fecha_actualizacion = null;
                         array_push($response,$perfil);
                       }



                     }

            }


          //retorno el array en json
           echo json_encode($response);

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

// Crea el registro
$app->post('/new_postulacion', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);
        $contador  =0;


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
                         ->andWhere("Propuestas.convocatoria = ".$request->getPut('idc'))
                         ->execute()
                         ->getFirst();

                       $postulaciones = $participante->propuestas->juradospostulados;

                       //echo "cantidad-->".$postulaciones->count();
                       foreach ($postulaciones as $postulacion) {

                         //si la convocatoria esta activa y esta publicada
                          if($postulacion->convocatorias->active && $postulacion->convocatorias->estado == 5){
                            $contador++;
                          }

                        }

                        $nummax = Tablasmaestras::findFirst(
                          [
                          " nombre = 'numero_maximo_postulaciones_jurado'"
                          ]
                        );

                        //echo "sssss--->>>".(int)$nummax->valor."  contador-->".$contador;
                      //limite tabla maestra
                       if( $contador < (int)$nummax->valor){
                         //guardar registro

                         $juradopostulado = new Juradospostulados();
                         $juradopostulado->propuesta = $participante->propuestas->id;
                         $juradopostulado->convocatoria = $request->getPut('idregistro');
                         $juradopostulado->estado =  9;
                         $juradopostulado->creado_por = $user_current["id"];
                         $juradopostulado->fecha_creacion = date("Y-m-d H:i:s");
                         $juradopostulado->tipo_postulacion = 'Inscrita';
                         $juradopostulado->active =  true;


                         if ($juradopostulado->save() === false) {
                           //  return json_encode($user_current);

                            //Para auditoria en versión de pruebas
                            foreach ($juradopostulado->getMessages() as $message) {
                                 echo $message;
                               }
                         }

                        // return "registro guardado";
                         return $juradopostulado->id;

                       }else{
                         return "error_limite";
                       }

                     }else {
                           return "error";
                       }

            } else {
                return "acceso_denegado";
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        echo "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
    }
}
);

//Busca los registros de postulaciones
$app->get('/search_postulacion', function () use ($app, $config) {
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

                       $postulaciones = $participante->propuestas->juradospostulados;

                      //  echo json_encode($convocatorias);

                       foreach ($postulaciones as $postulacion) {

                         $area =  Areas::findFirst(
                           ["id=".$postulacion->convocatorias->area]
                         );
                         $postulacion->convocatorias->area = $area->nombre;

                         $lineaestrategica =  Lineasestrategicas::findFirst(
                           ["id=".$postulacion->convocatorias->linea_estrategica]
                         );
                        $postulacion->convocatorias->linea_estrategica = $lineaestrategica->nombre;

                         $programa =  Programas::findFirst(
                           ["id=".$postulacion->convocatorias->programa]
                         );
                        $postulacion->convocatorias->programa = $programa->nombre;

                         $entidad =  Entidades::findFirst(
                           ["id=".$postulacion->convocatorias->entidad]
                         );
                         $postulacion->convocatorias->entidad = $entidad->nombre;

                         $enfoque =  Enfoques::findFirst(
                           ["id=".$postulacion->convocatorias->enfoque]
                         );
                         $postulacion->convocatorias->enfoque = $enfoque->nombre;


                         $postulacion->convocatorias->creado_por = null;
                         $postulacion->convocatorias->actualizado_por = null;

                        // array_push($response,$postulacion->convocatorias);
                         array_push($response,["postulacion"=>$postulacion, "convocatoria"=>$postulacion->convocatorias]);
                       }



                     }

            }


            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($postulaciones->count()),
                "recordsFiltered" => intval($postulaciones->count()),
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

// Eliminar registro
$app->delete('/delete_postulacion/{id:[0-9]+}', function ($id) use ($app, $config) {
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

                    $participante = Participantes::query()
                      ->join("Usuariosperfiles","Participantes.usuario_perfil = Usuariosperfiles.id")
                      ->join("Propuestas"," Participantes.id = Propuestas.participante")
                       //perfil = 17  perfil de jurado
                      ->where("Usuariosperfiles.perfil = 17 ")
                      ->andWhere("Usuariosperfiles.usuario = ".$user_current["id"])
                      ->andWhere("Propuestas.convocatoria = ".$request->getPut('idc'))
                      ->execute()
                      ->getFirst();

                      $juradospostulado = Juradospostulados::findFirst($id);

                    if( $juradospostulado != null and $juradospostulado->estado == 9 ){

                      if($juradospostulado->active==true){
                          $juradospostulado->active=false;
                          $retorna="No";
                      }else{
                          $juradospostulado->active=true;
                          $retorna="Si";
                      }

                      $juradospostulado->actualizado_por = $user_current["id"];
                      $juradospostulado->fecha_actualizacion = date("Y-m-d H:i:s");

                      if ($juradospostulado->save($post) === false) {
                        //Para auditoria en versión de pruebas
                        foreach ($juradospostulado->getMessages() as $message) {
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
        //echo "error_metodo".$ex->getMessage();
        //Para auditoria en versión de pruebas
        return "error_metodo ". $ex->getMessage().$ex->getTraceAsString ();
    }
});




try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage().$e->getTraceAsString ();
}
?>
