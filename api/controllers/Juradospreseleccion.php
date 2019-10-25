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


           $array["entidades"]= Entidades::find("active = true");

           for($i = date("Y"); $i >= 2016; $i--){
               $array["anios"][] = $i;
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
                      ." AND active = true "
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

//Retorna información de id y nombre de las convocatorias
$app->get('/select_categorias', function () use ($app) {
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
            if( $request->get('convocatoria') )
            {

               $rs= Convocatorias::find(
                  [
                      " convocatoria_padre_categoria = ".$request->get('convocatoria')
                      ." AND estado = 5 "
                      ." AND active = true "
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

//Retorna información de los jurados preseleccionados (postulados+banco)
$app->get('/all_preseleccionados', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $convocatorias =  array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual != false ) {

          //se establecen los valores del usuario
          $user_current = json_decode($token_actual->user_current, true);
          $response = array();

           if( $user_current["id"]){


             //busca los que se postularon
             if( $request->get('convocatoria')){

               $juradospostulados = Juradospostulados::find(
                 [
                   " convocatoria = ".$request->get('convocatoria')
                 ]
               );

               if( $juradospostulados->count() > 0 ){

                 foreach ($juradospostulados as $juradopostulado) {

                    array_push( $response, [
                      "postulado" => true,
                      "id" =>  $juradopostulado->propuestas->participantes->id,
                      "tipo_documento" =>  $juradopostulado->propuestas->participantes->tipo_documento,
                      "numero_documento" =>  $juradopostulado->propuestas->participantes->numero_documento,
                      "nombres" =>  $juradopostulado->propuestas->participantes->primer_nombre." ".$juradopostulado->propuestas->participantes->segundo_nombre,
                      "apellidos" =>  $juradopostulado->propuestas->participantes->primer_apellido." ".$juradopostulado->propuestas->participantes->segundo_apellido,
                      "puntaje" =>  $juradopostulado->total_evaluacion
                      ] );
                 }

               }
             }


             //Se contruye la consulta con los filtros
             //echo "filtros-->".$request->get("filtros")[0]["name"];

             $where = '';
             $from = '';

             if($request->get('filtros')){
              // echo "where-->>".$request->get('filtros')[0]["value"];

                foreach ( $request->get('filtros') as $key => $value) {
                  $where = $where.( ( $value["name"] === 'palabra_clave' && $value["value"] != null && $value["value"] != '' ) ?
                                        " AND p.resumen like '%".$request->get('filtros')[0]["value"]."%' " :
                                        '');
                  /*
                  $where = $where.( ( $value["name"] === 'experto_con' && $value["value"] != null && $value["value"] != '' ) ?
                                        " AND ef.propuesta is not null" :
                                        '');
                  $where = $where.( ( $value["name"] === 'exp_menor_3' && $value["value"] != null && $value["value"] != '' ) ?
                                        " AND ef.propuesta is not null" :
                                        '');
                    */

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
            $participante = Participantes::findFirst($request->get('participante'));

            if( $participante->id != null ){

            //  $new_participante = clone $old_participante;

              $participante->tipo_documento = Tiposdocumentos::findFirst($participante->tipo_documento)->descripcion;
              $participante->sexo = Sexos::findFirst($participante->sexo)->nombre;
              $participante->ciudad_residencia = Ciudades::findFirst($participante->ciudad_residencia)->nombre;
              $participante->fecha_creacion = null;
              $participante->participante_padre = null;

              //Asigno el participante al array
              $array["participante"] = $participante;
              $array["perfil"] = $participante->propuestas->resumen;


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

                       $participante = Participantes::findFirst($request->get('participante'));

                       $educacionformales = Educacionformal::find(
                         [
                           " propuesta = ".$participante->propuestas->id
                           ." AND titulo LIKE '%".$request->get("search")['value']."%'"
                           ." OR institucion LIKE '%".$request->get("search")['value']."%'",
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

                       $participante = Participantes::findFirst($request->get('participante'));

                       $educacionnoformales = Educacionnoformal::find(
                         [
                           " propuesta = ".$participante->propuestas->id
                           ." AND nombre LIKE '%".$request->get("search")['value']."%'"
                           ." OR institucion LIKE '%".$request->get("search")['value']."%'",
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

                        $participante = Participantes::findFirst($request->get('participante'));

                       $experiencialaborales = Experiencialaboral::find(
                         [
                           " propuesta= ".$participante->propuestas->id
                           ." AND entidad LIKE '%".$request->get("search")['value']."%'"
                           ." OR cargo LIKE '%".$request->get("search")['value']."%'",
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

                       $participante = Participantes::findFirst($request->get('participante'));

                       $experienciajurados = Experienciajurado::find(
                         [
                           " propuesta = ".$participante->propuestas->id
                           ." AND nombre LIKE '%".$request->get("search")['value']."%'"
                           ." OR entidad LIKE '%".$request->get("search")['value']."%'"
                           ." OR anio LIKE '%".$request->get("search")['value']."%'",
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

                        $participante = Participantes::findFirst($request->get('participante'));

                       $reconocimientos = Propuestajuradoreconocimiento::find(
                         [
                           " propuesta= ".$participante->propuestas->id
                           ." AND nombre LIKE '%".$request->get("search")['value']."%'"
                           ." OR institucion LIKE '%".$request->get("search")['value']."%'"
                           ." OR anio LIKE '%".$request->get("search")['value']."%'",
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
                           ." AND nombre LIKE '%".$request->get("search")['value']."%'"
                           ." OR institucion LIKE '%".$request->get("search")['value']."%'"
                           ." OR anio LIKE '%".$request->get("search")['value']."%'"
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
                      $participante = Participantes::findFirst($request->get('participante'));

                       $publicaciones = Propuestajuradopublicacion::find(
                         [
                           " propuesta= ".$participante->propuestas->id
                           ." AND titulo LIKE '%".$request->get("search")['value']."%'"
                           ." OR tema LIKE '%".$request->get("search")['value']."%'"
                           ." OR anio LIKE '%".$request->get("search")['value']."%'",
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

//Busca los registros de educacion formal
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
              $participante = Participantes::findFirst($request->get('participante'));

              $rondas = $participante->propuestas->convocatorias->convocatoriasrondas;

                //echo  json_encode($rondas);
              foreach ($rondas as $ronda) {

                if($ronda->active){

                    //se construye el array de grupos d ecriterios
                    $grupo_criterios=array();
                    //se cronstruye el array de criterios
                    $criterios=array();

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

                         $obj[$categoria][$criterio->orden]=  $criterio;

                         //$obj[$categoria] = $criterio;

                        }

                      }

                      $criterios[$orden]= $obj ;
                    }


                    $response[$ronda->numero_ronda]= ["ronda"=>$ronda,"criterios"=>$criterios];

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



try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage();
}
?>
