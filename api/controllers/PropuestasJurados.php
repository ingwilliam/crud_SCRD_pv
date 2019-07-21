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
                         //Estado 9	Registrada
                         $propuesta->estado = 9;

                         $new_participante->propuestas = $propuesta;

                         if ($new_participante->save($post) === false) {

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

        echo "error_metodo";

      //Para auditoria en versión de pruebas
      //echo "error_metodo" . $ex->getMessage();
    }
}
);


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

                  //valido si la propuesta tiene el estado 9 (registrada)
                  if( $propuesta != null and $propuesta->estado == 9 ){
                      //return json_encode($post);
                      $participante->actualizado_por = $user_current["id"];
                      $participante->fecha_actualizacion = date("Y-m-d H:i:s");

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


try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage();
}
?>
