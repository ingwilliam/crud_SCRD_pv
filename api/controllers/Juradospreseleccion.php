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
use Phalcon\Logger\Adapter\File as FileAdapter;
use Phalcon\Logger\Formatter\Line;

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
        "host" => $config->database->host, "port" => $config->database->port,
        "username" => $config->database->username,
        "password" => $config->database->password,
        "dbname" => $config->database->name
            )
    );
});


//Funcionalidad para crear los log de la aplicación
//la carpeta debe tener la propietario y usuario
//sudo chown -R www-data:www-data log/
//https://docs.phalcon.io/3.4/es-es/logging
$formatter = new Line('{"date":"%date%","type":"%type%",%message%},');
$formatter->setDateFormat('Y-m-d H:i:s');
$logger = new FileAdapter($config->sistema->path_log . "convocatorias." . date("Y-m-d") . ".log");
$logger->setFormatter($formatter);

$app = new Micro($di);

//inicializa el formulario
$app->get('/init', function () use ($app, $config) {
    try {

        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $array = array();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);

            if ($user_current["id"]) {

                //busca las convocatorias de jurados
                $convocatorias_jurados = Convocatorias::find(
                                [
                                    "modalidad = 2" //busca las convocatorias de jurados
                                    . " AND active = true"
                                    . " AND estado =  5" //publicada
                                    . " AND anio =  " . date("Y") //solo listar el banco de jurados correspondiente al año en curso
                                ]
                );



                $array["convocatorias_jurados"] = array();
                foreach ($convocatorias_jurados as $key => $convocatoria) {
                    array_push($array["convocatorias_jurados"], ["id" => $convocatoria->id, "nombre" => $convocatoria->nombre]);
                }

                /*
                 * 20-05-2020
                 * Wilmer Gustavo Mogollón Duque
                 * Se agrega array para listar área de conocimiento
                 */

                //Busca las áreas de conocimiento
                $area_conocimientos = Categoriajurado::find("active=true AND tipo='formal' AND id=nodo");

                $array["area_conocimientos"] = array();
                foreach ($area_conocimientos as $key => $area_conocimiento) {
                    array_push($array["area_conocimientos"], ["id" => $area_conocimiento->id, "nombre" => $area_conocimiento->nombre]);
                }


                $array["entidades"] = Entidades::find("active = true");

                for ($i = date("Y"); $i >= 2016; $i--) {
                    $array["anios"][] = $i;
                }

                //Retorno el array
                return json_encode($array);
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {

        //return "error_metodo";
        //Para auditoria en versión de pruebas
        return "error_metodo: " . $ex->getMessage() . json_encode($ex->getTrace());
    }
});

//Retorna información de id y nombre de las convocatorias
$app->get('/select_convocatorias', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $convocatorias = array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Si existe consulto la convocatoria
            if ($request->get('entidad') && $request->get('anio')) {

                /*
                 * 20-04-2021
                 * Wilmer Gustavo Mogollón Duque
                 * Se agregan estados a la consulta para que liste todas las convocatorias
                 * 5	convocatorias	Publicada
                 * 6	convocatorias	Adjudicada
                 * 32	convocatorias	Cancelada
                 * 43	convocatorias	Desierta
                 * 45	convocatorias	Suspendida
                 */
                
                $rs = Convocatorias::find(
                                [
                                    " entidad = " . $request->get('entidad')
                                    . " AND anio = " . $request->get('anio')
                                    . " AND estado in (5, 6, 32, 43, 45) "
                                    . " AND modalidad != 2 " //2	Jurados
                                    . " AND active = true "
                                    . " AND convocatoria_padre_categoria is NULL"
                                ]
                );


                foreach ($rs as $convocatoria) {
                    array_push($convocatorias, ["id" => $convocatoria->id, "nombre" => $convocatoria->nombre]);
                }
            }

            return json_encode($convocatorias);
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        return "error_metodo" . $ex->getMessage();
    }
}
);

//Retorna información de id y nombre de las categorias de la convocatoria
$app->get('/select_categorias', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $response = array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Si existe consulto la convocatoria
            if ($request->get('convocatoria')) {

                $convocatoria = Convocatorias::findFirst($request->get('convocatoria'));

                if ($convocatoria->tiene_categorias) {
                    $categorias = Convocatorias::find(
                                    [
                                        " convocatoria_padre_categoria = " . $convocatoria->id
                                        . " AND active = true "
                                    ]
                    );
                }


                //Se construye un array con la información de id y nombre de cada convocatoria para establece rel componente select
                //foreach ( $rs as $key => $value) {
                //      $nucleosbasicos[$key]= array("id"=>$value->id, "nombre"=>$value->nombre);
                //}

                foreach ($categorias as $categoria) {
                    array_push($response, ["id" => $categoria->id, "nombre" => $categoria->nombre]);
                }
            }

            return json_encode($response);
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        return "error_metodo" . $ex->getMessage();
    }
}
);

//Retorna información de los jurados preseleccionados (postulados+banco)
$app->get('/all_preseleccionados', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //  $juradospostulados =  array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);
            $response = array();

            if ($user_current["id"]) {


                /*
                 * 19-05-2020
                 * Wilmer Gustavo Mogollón Duque
                 * Se ajusta la consulta para que ordene por aplica perfil y luego por total de evaluación de forma descendente
                 */


                //busca los que se postularon
                if ($request->get('convocatoria')) {

                    $convocatoria = Convocatorias::findFirst($request->get('convocatoria'));


                    //la convocatoria tiene categorias y son diferentes?, caso 3
                    if ($convocatoria->tiene_categorias && $convocatoria->diferentes_categorias && $request->get('categoria')) {

                        $juradospostulados = Juradospostulados::find(
                                        [
                                            " convocatoria = " . $request->get('categoria')
                                            . " AND active = true ",
                                            "order" => "aplica_perfil desc, total_evaluacion desc, estado desc"
                                        ]
                        );
                    } elseif ($convocatoria->tiene_categorias && !$convocatoria->diferentes_categorias && $request->get('categoria')) {
                        $juradospostulados = Juradospostulados::find(
                                        [
                                            " convocatoria = " . $request->get('categoria')
                                            . " AND active = true ",
                                            "order" => "aplica_perfil desc, total_evaluacion desc, estado desc"
                                        ]
                        );
                    } elseif ($convocatoria->tiene_categorias && !$convocatoria->diferentes_categorias && !$request->get('categoria')) {
                        $juradospostulados = Juradospostulados::find(
                                        [
                                            " convocatoria = " . $request->get('convocatoria')
                                            . " AND active = true ",
                                            "order" => "aplica_perfil desc, total_evaluacion desc, estado desc"
                                        ]
                        );
                    } else {
                        $juradospostulados = Juradospostulados::find(
                                        [
                                            " convocatoria = " . $request->get('convocatoria')
                                            . " AND active = true ",
                                            "order" => "aplica_perfil desc, total_evaluacion desc, estado desc"
                                        ]
                        );
                    }


                    if ($juradospostulados->count() > 0) {

                        foreach ($juradospostulados as $juradopostulado) {

                            if ($juradopostulado->total_evaluacion == null) {
                                $juradopostulado->total_evaluacion = 0;
                            }

                            array_push($response, [
                                "postulado" => ( $juradopostulado->tipo_postulacion == 'Inscrita' ? true : false ),
                                "id" => $juradopostulado->propuestas->participantes->id,
                                "tipo_documento" => $juradopostulado->propuestas->participantes->tiposdocumentos->nombre,
                                "numero_documento" => $juradopostulado->propuestas->participantes->numero_documento,
                                "nombres" => $juradopostulado->propuestas->participantes->primer_nombre . " " . $juradopostulado->propuestas->participantes->segundo_nombre,
                                "apellidos" => $juradopostulado->propuestas->participantes->primer_apellido . " " . $juradopostulado->propuestas->participantes->segundo_apellido,
                                "id_postulacion" => $juradopostulado->id,
                                "puntaje" => $juradopostulado->total_evaluacion,
                                "aplica_perfil" => $juradopostulado->aplica_perfil,
                                "estado_postulacion" => $juradopostulado->estado
                            ]);
                        }
                    }
                }


                //Se contruye la consulta con los filtros
                //echo "filtros-->".$request->get("filtros")[0]["name"];

                $where = '';
                $from = '';


                if ($request->get('filtros')) {
                    // echo "where-->>".$request->get('filtros')[0]["value"];
                    //$conv = ( $request->get("categoria") == ''? ( $request->get("convocatoria")=''? -1 :  $request->get("convocatoria") ) : $request->get("categoria") );
                    $conv = ( $request->get("categoria") == '' ? $request->get("convocatoria") : $request->get("categoria") );

                    $query = ' SELECT
  	                     pro.*
                   FROM
  	                   Propuestas as pro
  	                   inner join Participantes as part
  	                   on pro.participante = part.id
  	                   inner join Usuariosperfiles as uper
  	                   on part.usuario_perfil = uper.id
                           
  	               WHERE
  	                   uper.perfil=17'
                            // -- busca los que se postularon a la convocatoria de jurados
                            . ' AND pro.convocatoria = ' . $request->get('filtros')[0]["value"]
                            //-- busca el último participante creado del usuario perfil
                            . ' AND part.fecha_creacion = ( select max( pa.fecha_creacion)
  								                                 from
  									                                        Participantes pa
  								                                 where
  									                                        pa.usuario_perfil= part.usuario_perfil
  								                                ) '
                            //-- excluye los que estan en la convocatoria selecionada o la convocatoria_padre_categoria
                            . ' AND part.usuario_perfil not in ( select part.usuario_perfil
  									                                        from
  										                                                Juradospostulados j
  										                                                inner join Propuestas as p
  										                                                on j.propuesta = p.id
  										                                                inner join Participantes as part
  										                                                on p.participante = part.id
                                                                      left join Convocatorias as c
                                                                      on j.convocatoria = c.id
  									                                        where'
                            //--id convocatoria seleccionada
                            . '        c.id = ' . $conv . ' OR c.convocatoria_padre_categoria = ' . $conv . '
                                                                 )
                            AND pro.estado in (10,11,12,13)
                          ';


                    /*
                     * 19-05-2020
                     * Wilmer Gustavo Mogollón Duque
                     * Se agrega restricción para que solo traiga jurados que hayan inscrito su hoja de vida
                     */



                    //  echo "banco--->".json_encode($propuestas);
                    //---F I L T R O S---


                    foreach ($request->get('filtros') as $key => $value) {

                        if ($value["name"] === 'palabra_clave') {
                            //--Titulo universiatrio
                            $query = $query . " AND pro.id in ( select
                                            p.id
                                          from
                                            Propuestas p
                                          where lower(p.resumen) like '%" . strtolower($value["value"]) . "%'
                                          group by 1
                                          )
                                      ";
                        }

                        //-- codigo > 5 es titulo universitario (Profesional,Especialización,Maestría y Doctorado)

                        if ($value["name"] === 'experto_sin' && $value["value"] === 'on') {
                            //--Titulo universiatrio
                            $query = $query . " AND pro.id in ( select
                                       p.id
                     from
               Propuestas p
               inner join Educacionformal ef
               on p.id = ef.propuesta
                     where ef.nivel_educacion <= 5
                     AND pro.id not in 
                                       (select p.id from Propuestas p inner join Educacionformal ef on p.id = ef.propuesta where ef.nivel_educacion > 5)
                                        )
                                       ";
                        }



                        if ($value["name"] === 'exp_jurado' && $value["value"] === 'on') {
                            //--Titulo universiatrio
                            $query = $query . " AND pro.id in ( select
                                      						p.id
                                      					from
                                      						Propuestas p
                                      						inner join Experienciajurado ej
                                      						on p.id = ej.propuesta
                                      					group by 1
                                      					)
                                        ";
                        }

                        if ($value["name"] === 'reconocimiento' && $value["value"] === 'on') {
                            //--Titulo universiatrio
                            $query = $query . " AND pro.id in ( select
                                        						p.id
                                        					from
                                        						Propuestas p
                                        						inner join Propuestajuradoreconocimiento pjr
                                        						on p.id = pjr.propuesta
                                        					group by 1
                                        					)
                                        ";
                        }

                        if ($value["name"] === 'publicaciones' && $value["value"] === 'on') {
                            //--Titulo universiatrio
                            $query = $query . " AND pro.id in ( select
                                  						p.id
                                  					from
                                  						Propuestas p
                                  						inner join Propuestajuradopublicacion pjp
                                  						on p.id = pjp.propuesta
                                  					group by 1
                                                                )
                                        ";
                        }

                        /*
                         * Wilmer Mogollón
                         */


                        if ($value["name"] === 'area_conocimiento' && $value["value"] != "") {
                            //--Titulo universiatrio
                            $query = $query . " AND pro.id in ( 
                                                                  
                                                                select p.id
                                                                  from Propuestas p inner join Educacionformal ef on p.id=ef.propuesta 
                                                                  where ef.area_conocimiento = " . strtolower($value["value"]) . "
                                                                  group by 1
                                          )
                                      ";
                        }
                    }//fin for
                    //se ejecuta la consulta
                    $propuestas = $this->modelsManager->executeQuery($query);

                    foreach ($propuestas as $propuesta) {

                        array_push($response, [
                            "postulado" => false,
                            "id" => $propuesta->participantes->id,
                            "tipo_documento" => $propuesta->participantes->tiposdocumentos->nombre,
                            "numero_documento" => $propuesta->participantes->numero_documento,
                            "nombres" => $propuesta->participantes->primer_nombre . " " . $propuesta->participantes->segundo_nombre,
                            "apellidos" => $propuesta->participantes->primer_apellido . " " . $propuesta->participantes->segundo_apellido,
                            "id_postulacion" => null,
                            "puntaje" => null,
                            "aplica_perfil" => false,
                            "estado_postulacion" => null
                        ]);
                    }

                    //$banco =
                }
            }

            //return json_encode($juradospostulados);
            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval(count($response)),
                "recordsFiltered" => intval(count($response)),
                "data" => $response   // total data array
            );
            //retorno el array en json
            return json_encode($json_data);
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        return "error_metodo" . $ex->getMessage();
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
        $delimiter = array("[", "]", "\"");

        //  $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);

            if ($user_current["id"]) {

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
                if ($participante->id != null) {

                    //  $new_participante = clone $old_participante;

                    /* $participante->tipo_documento = Tiposdocumentos::findFirst($participante->tipo_documento)->descripcion;
                      $participante->sexo = Sexos::findFirst($participante->sexo)->nombre;
                      $participante->ciudad_residencia = Ciudades::findFirst($participante->ciudad_residencia)->nombre;
                      $participante->fecha_creacion = null;
                      $participante->participante_padre = null;

                      //Asigno el participante al array
                      $array["participante"] = $participante;
                      $array["perfil"] = $participante->propuestas->resumen; */

                    /* $array["participantes"] =  Convocatoriasparticipantes::find([
                      " convocatoria = ".$request->get('idc')
                      ." AND tipo_participante = 4 "
                      ." AND active = true ",
                      "order" => 'orden ASC',
                      ]);
                     */

                    $participante = Convocatoriasparticipantes::findFirst($postulacion->perfil);

                    //se modifican los valores de algunas propiedades de cada registro

                    $participante->area_perfil = str_replace($delimiter, "", $value->area_perfil);
                    $participante->area_conocimiento = str_replace($delimiter, "", $value->area_conocimiento);
                    $participante->nivel_educativo = str_replace($delimiter, "", $value->nivel_educativo);
                    $participante->formacion_profesional = ($value->formacion_profesional) ? "Si" : "No";
                    $participante->formacion_postgrado = ($value->formacion_postgrado) ? "Si" : "No";
                    $participante->reside_bogota = ($value->reside_bogota) ? "Si" : "No";

                    $array["participante"] = $participante;
                } else {


                    if ($convocatoria->tiene_categorias && $convocatoria->diferentes_categorias) {


                        $participantes = Convocatoriasparticipantes::find([
                                    "convocatoria = " . $request->get('categoria')
                                    . " AND tipo_participante = 4"
                        ]);

                        foreach ($participantes as $key => $value) {
                            $value->area_perfil = str_replace($delimiter, "", $value->area_perfil);
                            $value->area_conocimiento = str_replace($delimiter, "", $value->area_conocimiento);
                            $value->nivel_educativo = str_replace($delimiter, "", $value->nivel_educativo);
                            $value->formacion_profesional = ($value->formacion_profesional) ? "Si" : "No";
                            $value->formacion_postgrado = ($value->formacion_postgrado) ? "Si" : "No";
                            $value->reside_bogota = ($value->reside_bogota) ? "Si" : "No";
                            $a[$key] = $value;
                            array_push($array["participantes"], $value);
                        }
                    } else {



                        $participantes = Convocatoriasparticipantes::find([
                                    " convocatoria = " . $request->get('convocatoria')
                                    . " AND tipo_participante = 4"
                        ]);

                        foreach ($participantes as $key => $value) {
                            $value->area_perfil = str_replace($delimiter, "", $value->area_perfil);
                            $value->area_conocimiento = str_replace($delimiter, "", $value->area_conocimiento);
                            $value->nivel_educativo = str_replace($delimiter, "", $value->nivel_educativo);
                            $value->formacion_profesional = ($value->formacion_profesional) ? "Si" : "No";
                            $value->formacion_postgrado = ($value->formacion_postgrado) ? "Si" : "No";
                            $value->reside_bogota = ($value->reside_bogota) ? "Si" : "No";
                            $a[$key] = $value;
                            array_push($array["participantes"], $value);
                        }
                    }
                }

                return json_encode($array);
            } else {
                echo "error";
            }
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {

        //  echo "error_metodo";
        //Para auditoria en versión de pruebas
        echo "error_metodo" . $ex->getMessage() . json_encode($ex->getTrace());
    }
});


/* * Busca la información básica del jurado
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
        if (isset($token_actual->id)) {
            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);

            if ($user_current["id"]) {

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

                if ($request->get('postulacion') && $request->get('postulacion') == 'null') {

                    $participante = Participantes::findFirst($request->get('participante'));
                } elseif ($request->get('postulacion') && $request->get('postulacion') != 'null') {

                    $postulacion = Juradospostulados::findFirst($request->get('postulacion'));
                    if ($postulacion->perfil) {
                        $array["postulacion_perfil"] = Convocatoriasparticipantes::findFirst($postulacion->perfil);
                    }
                    $participante = $postulacion->propuestas->participantes;
                }

                if ($participante->id != null) {

                    //  $new_participante = clone $old_participante;

                    $participante->tipo_documento = Tiposdocumentos::findFirst($participante->tipo_documento)->descripcion;
                    $participante->sexo = Sexos::findFirst($participante->sexo)->nombre;
                    $participante->orientacion_sexual = Orientacionessexuales::findFirst($participante->orientacion_sexual)->nombre;
                    $participante->identidad_genero = Identidadesgeneros::findFirst($participante->identidad_genero)->nombre;
                    $participante->ciudad_residencia = Ciudades::findFirst($participante->ciudad_residencia)->nombre;
                    $participante->fecha_creacion = null;
                    $participante->participante_padre = null;

                    //Asigno el participante al array
                    $array["participante"] = $participante;
                    $array["propuesta_resumen"] = $participante->propuestas->resumen;
                    $array["modalidad_participa_jurado"] = $participante->propuestas->modalidad_participa;

                    //  $array["perfiles_covocatoria"] = Convocatoriasparticipantes::find("convocatoria = " $participante->propuestas->convocatorias->id
                } else {
                    $array["participante"] = new Participantes();
                }

                return json_encode($array);
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {

        //  echo "error_metodo";
        //Para auditoria en versión de pruebas
        echo "error_metodo" . $ex->getMessage() . json_encode($ex->getTrace());
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
        if (isset($token_actual->id)) {
            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);
            $response = array();
            $tdocumento = array();

            if ($user_current["id"]) {

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

                if ($request->get('postulacion') && $request->get('postulacion') == 'null') {

                    $participante = Participantes::findFirst($request->get('participante'));
                } elseif ($request->get('postulacion') && $request->get('postulacion') != 'null') {
                    $postulacion = Juradospostulados::findFirst($request->get('postulacion'));
                    $participante = $postulacion->propuestas->participantes;
                    $convocatoria = Convocatorias::findFirst([' id = ' . $postulacion->convocatoria]); //Se crea el objeto convocatoria
                }

                /*
                 * 09-11-2020
                 * Wilmer Gustavo Mogollón Duque
                 * Se incorpora a la funcionalidad la condición de que muestre unicamente 
                 * los documentos que el jurado adjuntó hasta 48 horas antes de cerrar la convocatoria 
                 */

                $tipo_evento = Tiposeventos::findFirst(['nombre = "Fecha de cierre"']);

                $cronograma = Convocatoriascronogramas::findFirst(
                                [
                                    ' tipo_evento = ' . $tipo_evento->id
                                    . ' AND convocatoria = ' . $convocatoria->id
                                    . ' AND active = true'
                                ]
                );

                //Se establece el plazo de cargar información de 48 horas antes de cerrar la convocatoria
                $plazocargar = strtotime('-48 hour', strtotime($cronograma->fecha_fin));

                $plazocargar = date('Y-m-j H:i:s', $plazocargar);



                $documentos = Propuestajuradodocumento::find(
                                [
                                    " propuesta = " . $participante->propuestas->id,
//                                    . " AND fecha_creacion <= '" . $plazocargar . "'", //Se agrega la condición a la consulta
                                    "order" => 'id ASC',
                                    "limit" => $request->get('length'),
                                    "offset" => $request->get('start'),
                                ]
                );

                foreach ($documentos as $documento) {

                    if (isset($documento->categoria_jurado)) {
                        $tipo = Categoriajurado::findFirst(
                                        ["id = " . $documento->categoria_jurado]
                        );
                        $documento->categoria_jurado = $tipo->nombre;
                    }

                    if (isset($documento->requisito)) {
                        $tipo = Convocatoriasdocumentos::findFirst(
                                        ["id = " . $documento->requisito]
                        );
                        $documento->categoria_jurado = $tipo->Requisitos->nombre;
                    }


                    $documento->creado_por = null;
                    $documento->actualizado_por = null;
                    array_push($response, $documento);
                }

                //resultado sin filtro
                $tdocumento = Propuestajuradodocumento::find([
                            " propuesta = " . $participante->propuestas->id,
                ]);
            }

            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($tdocumento->count()),
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
        return "error_metodo" . $ex->getMessage() . $ex->getTraceAsString();
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
        if (isset($token_actual->id)) {
            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);
            $response = array();

            if ($user_current["id"]) {

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

                if ($request->get('postulacion') && $request->get('postulacion') == 'null') {

                    $participante = Participantes::findFirst($request->get('participante'));
                } elseif ($request->get('postulacion') && $request->get('postulacion') != 'null') {

                    $postulacion = Juradospostulados::findFirst($request->get('postulacion'));
                    $participante = $postulacion->propuestas->participantes;
                    $convocatoria = Convocatorias::findFirst([' id = ' . $postulacion->convocatoria]); //Se crea el objeto convocatoria
                }

                /*
                 * 21-04-2021
                 * Wilmer Gustavo Mogollón Duque
                 * Se incorpora a la funcionalidad la condición de que muestre unicamente 
                 * los documentos que el jurado adjuntó hasta 48 horas antes de cerrar la convocatoria 
                 */
                //se agrega este if para validar la fecha de cierre, dependiendo de la nauraleza de la convocatoria
                
                if ($convocatoria->convocatoria_padre_categoria != null) {
                    
                    $convocatoria_padre= Convocatorias::findFirst([' id = '.$convocatoria->convocatoria_padre_categoria]);
                    
                    if($convocatoria_padre->diferentes_categorias==false){
                        $id_convocatoria = $convocatoria->convocatoria_padre_categoria;
                    }else{
                       $id_convocatoria = $convocatoria->id; 
                    }
                } else {
                    $id_convocatoria = $convocatoria->id;
                }

                $tipo_evento = Tiposeventos::findFirst(['nombre = "Fecha de cierre"']);

                $cronograma = Convocatoriascronogramas::findFirst(
                                [
                                    ' tipo_evento = ' . $tipo_evento->id
                                    . ' AND convocatoria = ' . $id_convocatoria
                                    . ' AND active = true'
                                ]
                );
                
//                return json_encode($cronograma);

                //Se establece el plazo de cargar información de 48 horas antes de cerrar la convocatoria
                $plazocargar = strtotime('-48 hour', strtotime($cronograma->fecha_fin));
                
//                return json_encode($plazocargar);

                $plazocargar = date('Y-m-j H:i:s', $plazocargar);

//                return json_encode($plazocargar);


                /*
                 * 17-11-2020
                 * Wilmer Gustavo Mogollón Duque
                 * Se incorpora validación para determinar si el tipo de postulación es inscrita.
                 */

                if ($postulacion->tipo_postulacion == 'Inscrita') {
                    $educacionformales = Educacionformal::find(
                                    [
                                        " propuesta = " . $participante->propuestas->id
                                        . " AND ( titulo LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR institucion LIKE '%" . $request->get("search")['value'] . "%')"
                                        . " AND fecha_creacion <= '" . $plazocargar . "'", //Se agrega la condición a la consulta
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );
                } else {
                    $educacionformales = Educacionformal::find(
                                    [
                                        " propuesta = " . $participante->propuestas->id
                                        . " AND ( titulo LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR institucion LIKE '%" . $request->get("search")['value'] . "%')",
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );
                }





                foreach ($educacionformales as $educacionformal) {

                    $ciudad = Ciudades::findFirst(
                                    [" active=true AND id=" . $educacionformal->ciudad]
                    );

                    $nivel_educativo = Niveleseducativos::findFirst(
                                    ["id = " . $educacionformal->nivel_educacion]
                    );
                    $educacionformal->nivel_educacion = $nivel_educativo->nombre;

                    $educacionformal->ciudad = $ciudad->nombre;
                    $educacionformal->creado_por = null;
                    $educacionformal->actualizado_por = null;
                    $educacionformal->numero_semestres = ( $educacionformal->numero_semestres == null) ? "N/D" : $educacionformal->numero_semestres;
                    array_push($response, $educacionformal);
                }

                //resultado sin filtro
                $teducacionformal = Educacionformal::find(
                                [
                                    " propuesta = " . $participante->propuestas->id
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
        echo "error_metodo" . $ex->getMessage() . $ex->getTraceAsString();
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
        if (isset($token_actual->id)) {
            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);
            $response = array();
            if ($user_current["id"]) {


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

                if ($request->get('postulacion') && $request->get('postulacion') == 'null') {

                    $participante = Participantes::findFirst($request->get('participante'));
                } elseif ($request->get('postulacion') && $request->get('postulacion') != 'null') {
                    $postulacion = Juradospostulados::findFirst($request->get('postulacion'));
                    $participante = $postulacion->propuestas->participantes;
                    $convocatoria = Convocatorias::findFirst([' id = ' . $postulacion->convocatoria]); //Se crea el objeto convocatoria
                }

                /*
                 * 09-11-2020
                 * Wilmer Gustavo Mogollón Duque
                 * Se incorpora a la funcionalidad la condición de que muestre unicamente 
                 * los documentos que el jurado adjuntó hasta 48 horas antes de cerrar la convocatoria 
                 */

                //se agrega este if para validar la fecha de cierre, dependiendo de la nauraleza de la convocatoria
                
                if ($convocatoria->convocatoria_padre_categoria != null) {
                    
                    $convocatoria_padre= Convocatorias::findFirst([' id = '.$convocatoria->convocatoria_padre_categoria]);
                    
                    if($convocatoria_padre->diferentes_categorias==false){
                        $id_convocatoria = $convocatoria->convocatoria_padre_categoria;
                    }else{
                       $id_convocatoria = $convocatoria->id; 
                    }
                } else {
                    $id_convocatoria = $convocatoria->id;
                }

                $tipo_evento = Tiposeventos::findFirst(['nombre = "Fecha de cierre"']);

                $cronograma = Convocatoriascronogramas::findFirst(
                                [
                                    ' tipo_evento = ' . $tipo_evento->id
                                    . ' AND convocatoria = ' . $id_convocatoria
                                    . ' AND active = true'
                                ]
                );

                //Se establece el plazo de cargar información de 48 horas antes de cerrar la convocatoria
                $plazocargar = strtotime('-48 hour', strtotime($cronograma->fecha_fin));

                $plazocargar = date('Y-m-j H:i:s', $plazocargar);



                /*
                 * 17-11-2020
                 * Wilmer Gustavo Mogollón Duque
                 * Se incorpora validación para determinar si el tipo de postulación es inscrita.
                 */

                if ($postulacion->tipo_postulacion == 'Inscrita') {
                    $educacionnoformales = Educacionnoformal::find(
                                    [
                                        " propuesta = " . $participante->propuestas->id
                                        . " AND ( nombre LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR institucion LIKE '%" . $request->get("search")['value'] . "%' )"
                                        . " AND fecha_creacion <= '" . $plazocargar . "'", //Se agrega la condición a la consulta
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );
                } else {
                    $educacionnoformales = Educacionnoformal::find(
                                    [
                                        " propuesta = " . $participante->propuestas->id
                                        . " AND ( nombre LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR institucion LIKE '%" . $request->get("search")['value'] . "%' )",
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );
                }


                foreach ($educacionnoformales as $educacionnoformal) {

                    $ciudad = Ciudades::findFirst(
                                    ["active=true AND id=" . $educacionnoformal->ciudad]
                    );

                    $educacionnoformal->ciudad = $ciudad->nombre;
                    $educacionnoformal->creado_por = null;
                    $educacionnoformal->actualizado_por = null;
                    array_push($response, $educacionnoformal);
                }

                //resultado sin filtro
                $teducacionnoformal = Educacionnoformal::find(
                                [
                                    " propuesta = " . $participante->propuestas->id
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
        echo "error_metodo" . $ex->getMessage() . $ex->getTraceAsString();
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
        if (isset($token_actual->id)) {
            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);
            $response = array();
            if ($user_current["id"]) {



                /*  $participante = Participantes::query()
                  ->join("Usuariosperfiles","Participantes.usuario_perfil = Usuariosperfiles.id")
                  ->join("Propuestas"," Participantes.id = Propuestas.participante")
                  //perfil = 17  perfil de jurado
                  ->where("Usuariosperfiles.perfil = 17 ")
                  ->andWhere("Usuariosperfiles.usuario = ".$user_current["id"])
                  ->andWhere("Propuestas.convocatoria = ".$request->get('idc'))
                  ->execute()
                  ->getFirst(); */

                //$participante = Participantes::findFirst($request->get('participante'));
                //$postulacion = Juradospostulados::findFirst($request->get('participante'));
                //$participante = $postulacion->propuestas->participantes;


                if ($request->get('postulacion') && $request->get('postulacion') == 'null') {

                    $participante = Participantes::findFirst($request->get('participante'));
                } elseif ($request->get('postulacion') && $request->get('postulacion') != 'null') {
                    $postulacion = Juradospostulados::findFirst($request->get('postulacion'));
                    $participante = $postulacion->propuestas->participantes;
                    $convocatoria = Convocatorias::findFirst([' id = ' . $postulacion->convocatoria]); //Se crea el objeto convocatoria
                }


                /*
                 * 09-11-2020
                 * Wilmer Gustavo Mogollón Duque
                 * Se incorpora a la funcionalidad la condición de que muestre unicamente 
                 * los documentos que el jurado adjuntó hasta 48 horas antes de cerrar la convocatoria 
                 */

                //se agrega este if para validar la fecha de cierre, dependiendo de la nauraleza de la convocatoria
                
                if ($convocatoria->convocatoria_padre_categoria != null) {
                    
                    $convocatoria_padre= Convocatorias::findFirst([' id = '.$convocatoria->convocatoria_padre_categoria]);
                    
                    if($convocatoria_padre->diferentes_categorias==false){
                        $id_convocatoria = $convocatoria->convocatoria_padre_categoria;
                    }else{
                       $id_convocatoria = $convocatoria->id; 
                    }
                } else {
                    $id_convocatoria = $convocatoria->id;
                }

                $tipo_evento = Tiposeventos::findFirst(['nombre = "Fecha de cierre"']);

                $cronograma = Convocatoriascronogramas::findFirst(
                                [
                                    ' tipo_evento = ' . $tipo_evento->id
                                    . ' AND convocatoria = ' . $id_convocatoria
                                    . ' AND active = true'
                                ]
                );

                //Se establece el plazo de cargar información de 48 horas antes de cerrar la convocatoria
                $plazocargar = strtotime('-48 hour', strtotime($cronograma->fecha_fin));

                $plazocargar = date('Y-m-j H:i:s', $plazocargar);


                /*
                 * 17-11-2020
                 * Wilmer Gustavo Mogollón Duque
                 * Se incorpora validación para determinar si el tipo de postulación es inscrita.
                 */

                if ($postulacion->tipo_postulacion == 'Inscrita') {
                    $experiencialaborales = Experiencialaboral::find(
                                    [
                                        " propuesta= " . $participante->propuestas->id
                                        . " AND ( entidad LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR cargo LIKE '%" . $request->get("search")['value'] . "%' )"
                                        . " AND fecha_creacion <= '" . $plazocargar . "'", //Se agrega la condición a la consulta
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );
                } else {
                    $experiencialaborales = Experiencialaboral::find(
                                    [
                                        " propuesta= " . $participante->propuestas->id
                                        . " AND ( entidad LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR cargo LIKE '%" . $request->get("search")['value'] . "%' )",
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );
                }




                foreach ($experiencialaborales as $experiencialaboral) {

                    $ciudad = Ciudades::findFirst(
                                    ["id=" . $experiencialaboral->ciudad]
                    );
                    $experiencialaboral->ciudad = $ciudad->nombre;

                    $linea = Lineasestrategicas::findFirst(
                                    ["id = " . $experiencialaboral->linea]
                    );
                    $experiencialaboral->linea = $linea->nombre;

                    $experiencialaboral->creado_por = null;
                    $experiencialaboral->actualizado_por = null;
                    array_push($response, $experiencialaboral);
                }

                //resultado sin filtro
                $texperiencialaboral = Experiencialaboral::find([
                            " propuesta= " . $participante->propuestas->id
                            . " AND entidad LIKE '%" . $request->get("search")['value'] . "%'"
                            . " OR cargo LIKE '%" . $request->get("search")['value'] . "%'"
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
        return "error_metodo" . $ex->getMessage() . $ex->getTraceAsString();
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
        if (isset($token_actual->id)) {
            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);
            $response = array();
            if ($user_current["id"]) {


                /*      $participante = Participantes::query()
                  ->join("Usuariosperfiles","Participantes.usuario_perfil = Usuariosperfiles.id")
                  ->join("Propuestas"," Participantes.id = Propuestas.participante")
                  //perfil = 17  perfil de jurado
                  ->where("Usuariosperfiles.perfil = 17 ")
                  ->andWhere("Usuariosperfiles.usuario = ".$user_current["id"])
                  ->andWhere("Propuestas.convocatoria = ".$request->get('idc'))
                  ->execute()
                  ->getFirst(); */

                //$participante = Participantes::findFirst($request->get('participante'));
                //$postulacion = Juradospostulados::findFirst($request->get('participante'));
                //$participante = $postulacion->propuestas->participantes;


                if ($request->get('postulacion') && $request->get('postulacion') == 'null') {

                    $participante = Participantes::findFirst($request->get('participante'));
                } elseif ($request->get('postulacion') && $request->get('postulacion') != 'null') {
                    $postulacion = Juradospostulados::findFirst($request->get('postulacion'));
                    $participante = $postulacion->propuestas->participantes;
                    $convocatoria = Convocatorias::findFirst([' id = ' . $postulacion->convocatoria]); //Se crea el objeto convocatoria
                }

                /*
                 * 09-11-2020
                 * Wilmer Gustavo Mogollón Duque
                 * Se incorpora a la funcionalidad la condición de que muestre unicamente 
                 * los documentos que el jurado adjuntó hasta 48 horas antes de cerrar la convocatoria 
                 */

                //se agrega este if para validar la fecha de cierre, dependiendo de la nauraleza de la convocatoria
                
                if ($convocatoria->convocatoria_padre_categoria != null) {
                    
                    $convocatoria_padre= Convocatorias::findFirst([' id = '.$convocatoria->convocatoria_padre_categoria]);
                    
                    if($convocatoria_padre->diferentes_categorias==false){
                        $id_convocatoria = $convocatoria->convocatoria_padre_categoria;
                    }else{
                       $id_convocatoria = $convocatoria->id; 
                    }
                } else {
                    $id_convocatoria = $convocatoria->id;
                }

                $tipo_evento = Tiposeventos::findFirst(['nombre = "Fecha de cierre"']);

                $cronograma = Convocatoriascronogramas::findFirst(
                                [
                                    ' tipo_evento = ' . $tipo_evento->id
                                    . ' AND convocatoria = ' . $id_convocatoria
                                    . ' AND active = true'
                                ]
                );

                //Se establece el plazo de cargar información de 48 horas antes de cerrar la convocatoria
                $plazocargar = strtotime('-48 hour', strtotime($cronograma->fecha_fin));

                $plazocargar = date('Y-m-j H:i:s', $plazocargar);


                /*
                 * 17-11-2020
                 * Wilmer Gustavo Mogollón Duque
                 * Se incorpora validación para determinar si el tipo de postulación es inscrita.
                 */

                if ($postulacion->tipo_postulacion == 'Inscrita') {
                    $experienciajurados = Experienciajurado::find(
                                    [
                                        " propuesta = " . $participante->propuestas->id
                                        . " AND ( nombre LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR entidad LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR anio LIKE '%" . $request->get("search")['value'] . "%' )"
                                        . " AND fecha_creacion <= '" . $plazocargar . "'", //Se agrega la condición a la consulta
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );
                } else {
                    $experienciajurados = Experienciajurado::find(
                                    [
                                        " propuesta = " . $participante->propuestas->id
                                        . " AND ( nombre LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR entidad LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR anio LIKE '%" . $request->get("search")['value'] . "%' )",
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );
                }


                foreach ($experienciajurados as $experienciajurado) {

                    $ciudad = Ciudades::findFirst(
                                    ["active=true AND id=" . $experienciajurado->ciudad]
                    );
                    $experienciajurado->ciudad = $ciudad->nombre;

                    $ambito = Categoriajurado::findFirst(
                                    ["active=true AND id=" . $experienciajurado->ambito]
                    );
                    $experienciajurado->ambito = $ambito->nombre;

                    $experienciajurado->creado_por = null;
                    $experienciajurado->actualizado_por = null;
                    array_push($response, $experienciajurado);
                }

                //resultado sin filtro
                $texperienciajurado = Experienciajurado::find(
                                [
                                    " propuesta = " . $participante->propuestas->id
                                    . " AND nombre LIKE '%" . $request->get("search")['value'] . "%'"
                                    . " OR entidad LIKE '%" . $request->get("search")['value'] . "%'"
                                    . " OR anio LIKE '%" . $request->get("search")['value'] . "%'",
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
        return "error_metodo" . $ex->getMessage() . $ex->getTraceAsString();
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
        if (isset($token_actual->id)) {
            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);
            $response = array();
            if ($user_current["id"]) {


                /*  $participante = Participantes::query()
                  ->join("Usuariosperfiles","Participantes.usuario_perfil = Usuariosperfiles.id")
                  ->join("Propuestas"," Participantes.id = Propuestas.participante")
                  //perfil = 17  perfil de jurado
                  ->where("Usuariosperfiles.perfil = 17 ")
                  ->andWhere("Usuariosperfiles.usuario = ".$user_current["id"])
                  ->andWhere("Propuestas.convocatoria = ".$request->get('idc'))
                  ->execute()
                  ->getFirst(); */

                //$participante = Participantes::findFirst($request->get('participante'));
                //$postulacion = Juradospostulados::findFirst($request->get('participante'));
                //$participante = $postulacion->propuestas->participantes;


                if ($request->get('postulacion') && $request->get('postulacion') == 'null') {

                    $participante = Participantes::findFirst($request->get('participante'));
                } elseif ($request->get('postulacion') && $request->get('postulacion') != 'null') {
                    $postulacion = Juradospostulados::findFirst($request->get('postulacion'));
                    $participante = $postulacion->propuestas->participantes;
                    $convocatoria = Convocatorias::findFirst([' id = ' . $postulacion->convocatoria]); //Se crea el objeto convocatoria
                }

                /*
                 * 09-11-2020
                 * Wilmer Gustavo Mogollón Duque
                 * Se incorpora a la funcionalidad la condición de que muestre unicamente 
                 * los documentos que el jurado adjuntó hasta 48 horas antes de cerrar la convocatoria 
                 */

                //se agrega este if para validar la fecha de cierre, dependiendo de la nauraleza de la convocatoria
                
                if ($convocatoria->convocatoria_padre_categoria != null) {
                    
                    $convocatoria_padre= Convocatorias::findFirst([' id = '.$convocatoria->convocatoria_padre_categoria]);
                    
                    if($convocatoria_padre->diferentes_categorias==false){
                        $id_convocatoria = $convocatoria->convocatoria_padre_categoria;
                    }else{
                       $id_convocatoria = $convocatoria->id; 
                    }
                } else {
                    $id_convocatoria = $convocatoria->id;
                }

                $tipo_evento = Tiposeventos::findFirst(['nombre = "Fecha de cierre"']);

                $cronograma = Convocatoriascronogramas::findFirst(
                                [
                                    ' tipo_evento = ' . $tipo_evento->id
                                    . ' AND convocatoria = ' . $id_convocatoria
                                    . ' AND active = true'
                                ]
                );

                //Se establece el plazo de cargar información de 48 horas antes de cerrar la convocatoria
                $plazocargar = strtotime('-48 hour', strtotime($cronograma->fecha_fin));

                $plazocargar = date('Y-m-j H:i:s', $plazocargar);

                /*
                 * 17-11-2020
                 * Wilmer Gustavo Mogollón Duque
                 * Se incorpora validación para determinar si el tipo de postulación es inscrita.
                 */

                if ($postulacion->tipo_postulacion == 'Inscrita') {
                    $reconocimientos = Propuestajuradoreconocimiento::find(
                                    [
                                        " propuesta= " . $participante->propuestas->id
                                        . " AND ( nombre LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR institucion LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR anio LIKE '%" . $request->get("search")['value'] . "%' )"
                                        . " AND fecha_creacion <= '" . $plazocargar . "'", //Se agrega la condición a la consulta
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );
                } else {
                    $reconocimientos = Propuestajuradoreconocimiento::find(
                                    [
                                        " propuesta= " . $participante->propuestas->id
                                        . " AND ( nombre LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR institucion LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR anio LIKE '%" . $request->get("search")['value'] . "%' )",
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );
                }



                foreach ($reconocimientos as $reconocimiento) {

                    $ciudad = Ciudades::findFirst(
                                    ["active=true AND id=" . $reconocimiento->ciudad]
                    );
                    $reconocimiento->ciudad = $ciudad->nombre;

                    $tipo = Categoriajurado::findFirst(
                                    ["active=true AND id=" . $reconocimiento->tipo]
                    );
                    $reconocimiento->tipo = $tipo->nombre;

                    $reconocimiento->creado_por = null;
                    $reconocimiento->actualizado_por = null;
                    array_push($response, $reconocimiento);
                }

                //resultado sin filtro
                $treconocimiento = Propuestajuradoreconocimiento::find(
                                [
                                    " propuesta= " . $participante->propuestas->id
                                    . " AND (nombre LIKE '%" . $request->get("search")['value'] . "%'"
                                    . " OR institucion LIKE '%" . $request->get("search")['value'] . "%'"
                                    . " OR anio LIKE '%" . $request->get("search")['value'] . "%')"
                                ]
                );
            }


            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($treconocimiento->count()),
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
        echo "error_metodo" . $ex->getMessage() . $ex->getTraceAsString();
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
        if (isset($token_actual->id)) {
            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);
            $response = array();
            if ($user_current["id"]) {

                /* $participante = Participantes::query()
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

                if ($request->get('postulacion') && $request->get('postulacion') == 'null') {

                    $participante = Participantes::findFirst($request->get('participante'));
                } elseif ($request->get('postulacion') && $request->get('postulacion') != 'null') {
                    $postulacion = Juradospostulados::findFirst($request->get('postulacion'));
                    $participante = $postulacion->propuestas->participantes;
                    $convocatoria = Convocatorias::findFirst([' id = ' . $postulacion->convocatoria]); //Se crea el objeto convocatoria
                }

                /*
                 * 09-11-2020
                 * Wilmer Gustavo Mogollón Duque
                 * Se incorpora a la funcionalidad la condición de que muestre unicamente 
                 * los documentos que el jurado adjuntó hasta 48 horas antes de cerrar la convocatoria 
                 */

                //se agrega este if para validar la fecha de cierre, dependiendo de la nauraleza de la convocatoria
                
                if ($convocatoria->convocatoria_padre_categoria != null) {
                    
                    $convocatoria_padre= Convocatorias::findFirst([' id = '.$convocatoria->convocatoria_padre_categoria]);
                    
                    if($convocatoria_padre->diferentes_categorias==false){
                        $id_convocatoria = $convocatoria->convocatoria_padre_categoria;
                    }else{
                       $id_convocatoria = $convocatoria->id; 
                    }
                } else {
                    $id_convocatoria = $convocatoria->id;
                }

                $tipo_evento = Tiposeventos::findFirst(['nombre = "Fecha de cierre"']);

                $cronograma = Convocatoriascronogramas::findFirst(
                                [
                                    ' tipo_evento = ' . $tipo_evento->id
                                    . ' AND convocatoria = ' . $id_convocatoria
                                    . ' AND active = true'
                                ]
                );

                //Se establece el plazo de cargar información de 48 horas antes de cerrar la convocatoria
                $plazocargar = strtotime('-48 hour', strtotime($cronograma->fecha_fin));

                $plazocargar = date('Y-m-j H:i:s', $plazocargar);

                /*
                 * 17-11-2020
                 * Wilmer Gustavo Mogollón Duque
                 * Se incorpora validación para determinar si el tipo de postulación es inscrita.
                 */

                if ($postulacion->tipo_postulacion == 'Inscrita') {
                    $publicaciones = Propuestajuradopublicacion::find(
                                    [
                                        " propuesta= " . $participante->propuestas->id
                                        . " AND ( titulo LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR tema LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR anio LIKE '%" . $request->get("search")['value'] . "%' )"
                                        . " AND fecha_creacion <= '" . $plazocargar . "'", //Se agrega la condición a la consulta
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );
                } else {
                    $publicaciones = Propuestajuradopublicacion::find(
                                    [
                                        " propuesta= " . $participante->propuestas->id
                                        . " AND ( titulo LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR tema LIKE '%" . $request->get("search")['value'] . "%'"
                                        . " OR anio LIKE '%" . $request->get("search")['value'] . "%' )",
                                        "order" => 'id ASC',
                                        "limit" => $request->get('length'),
                                        "offset" => $request->get('start'),
                                    ]
                    );
                }



                foreach ($publicaciones as $publicacion) {

                    $ciudad = Ciudades::findFirst(
                                    ["active=true AND id=" . $publicacion->ciudad]
                    );
                    $publicacion->ciudad = $ciudad->nombre;

                    $tipo = Categoriajurado::findFirst(
                                    ["active=true AND id=" . $publicacion->tipo]
                    );
                    $publicacion->tipo = $tipo->nombre;

                    $formato = Categoriajurado::findFirst(
                                    ["active=true AND id=" . $publicacion->formato]
                    );
                    $publicacion->formato = $formato->nombre;

                    $publicacion->creado_por = null;
                    $publicacion->actualizado_por = null;
                    array_push($response, $publicacion);
                }

                //resultado sin filtro
                $tpublicacion = Propuestajuradopublicacion::find(
                                [
                                    " propuesta= " . $participante->propuestas->id
                                    . " AND titulo LIKE '%" . $request->get("search")['value'] . "%'"
                                    . " OR tema LIKE '%" . $request->get("search")['value'] . "%'"
                                    . " OR anio LIKE '%" . $request->get("search")['value'] . "%'"
                                ]
                );
            }


            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($tpublicacion->count()),
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
        return "error_metodo" . $ex->getMessage() . $ex->getTraceAsString();
    }
}
);

/*
 * Carga los datos relacionados con los criterios de evaluacion
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
        if (isset($token_actual->id)) {
            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);
            $response = array();

            if ($user_current["id"]) {


                /* $participante = Participantes::query()
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

                //  $rondas = $postulacion->propuestas->convocatorias->convocatoriasrondas;
                //echo  json_encode($rondas);
                /*
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
                 */

                /* Cesar Britto, 2020-05-11,
                 * Se agrega para validar de que modalidad_participa no sea vacio ó nulo,
                 * si se da el caso retorna error
                 */
                if ($postulacion->propuestas->modalidad_participa == '' || $postulacion->propuestas->modalidad_participa == null) {
                    return "error_modalidad_participa";
                }

                /**
                 * modalidad_participa
                 * 'Experto sin título universitario'
                 * 'Experto con título universitario'
                 */
                /* Cesar Britto, 2020-05-11, se ajusta la consulta */
                $ronda = Convocatoriasrondas::findFirst(
                                [
                                    " nombre_ronda like '%" . $postulacion->propuestas->modalidad_participa . "%'"
                                    /* Cesar Britto, 2020-05-12, se ajusta la consulta agregando el id de la convocatoria de jurado */
                                    . " AND convocatoria = " . $postulacion->propuestas->convocatoria
                                ]
                );

                if ($ronda->active) {
                    //se construye el array de grupos de criterios
                    $grupo_criterios = array();
                    //se cronstruye el array de criterios
                    $criterios = array();

                    //Se crea el array en el orden de los criterios
                    foreach ($ronda->Convocatoriasrondascriterios as $criterio) {

                        if ($criterio->active) {
                            $grupo_criterios[$criterio->grupo_criterio] = $criterio->orden;
                        }
                    }

                    //de acuerdo con el orden, se crea al array de criterios
                    foreach ($grupo_criterios as $categoria => $orden) {

                        //$obj = ["grupo" => $categoria, "criterios"=> array()];
                        $obj = array();
                        $obj[$categoria] = array();

                        foreach ($ronda->Convocatoriasrondascriterios as $criterio) {

                            if ($criterio->active && $criterio->grupo_criterio === $categoria) {
                                // $obj[$categoria][$criterio->orden]=  $criterio;
                                $obj[$categoria][$criterio->orden] = [
                                    "id" => $criterio->id,
                                    "descripcion_criterio" => $criterio->descripcion_criterio,
                                    "puntaje_minimo" => $criterio->puntaje_minimo,
                                    "puntaje_maximo" => $criterio->puntaje_maximo,
                                    "orden" => $criterio->orden,
                                    "grupo_criterio" => $criterio->grupo_criterio,
                                    "exclusivo" => $criterio->exclusivo,
                                    "evaluacion" => Evaluacion::findFirst([
                                        "criterio = " . $criterio->id
                                        . " AND postulado = " . $postulacion->id
                                    ])
                                ];
                            }
                        }

                        $criterios[$orden] = $obj;
                    }


                    $response[$ronda->numero_ronda] = ["ronda" => $ronda, "postulacion" => $postulacion, "criterios" => $criterios];
                }
            }
            //retorno el array en json
            return json_encode($response);
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {

        //Para auditoria en versión de pruebas
        //return "error_metodo" . $ex->getMessage().$ex->getTraceAsString ();

        return "error_metodo";
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
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                if ($request->getPut('postulacion') && $request->getPut('postulacion') != '') {

                    $postulacion = Juradospostulados::findFirst($request->getPut('postulacion'));
                    $postulacion->actualizado_por = $user_current["id"];
                    $postulacion->fecha_actualizacion = date("Y-m-d H:i:s");
                } else {
                    $participante = Participantes::findFirst($request->getPut('participante'));

                    $postulacion = new Juradospostulados();

                    $postulacion->propuesta = $participante->Propuestas->id;
                    $postulacion->creado_por = $user_current["id"];
                    $postulacion->fecha_creacion = date("Y-m-d H:i:s");
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
                  ); */

                if (!$request->getPut('option_aplica_perfil')) {
                    return "error";
                }



                if ($request->getPut('categoria') && $request->getPut('categoria') != '') {
                    $postulacion->convocatoria = $request->getPut('categoria');
                } else {
                    $postulacion->convocatoria = $request->getPut('idc');
                }

                /*
                 * 10-11-2020
                 * Wilmer Gustavo Mogollón Duque
                 * Se incorpora información del misional y la fecha en la que se realiza la verificación del perfil.
                 */

                $misional = Usuarios::findFirst(
                                [
                                    ' id = ' . $user_current["id"]
                                ]
                );

                $nombre_misional = $misional->primer_nombre . " " . $misional->segundo_nombre . " " . $misional->primer_apellido . " " . $misional->segundo_apellido;

                $postulacion->descripcion_evaluacion = $request->getPut('descripcion_evaluacion') . " " . "Verificado por " . $nombre_misional . " El día " . date("Y-m-d H:i:s");
                $postulacion->aplica_perfil = $request->getPut('option_aplica_perfil');
                $postulacion->estado = 11; //11	jurados	Verificado
                $postulacion->active = true;


                //actualiza la postulación
                if ($postulacion->save() === false) {
                    //return "error";
                    //Para auditoria en versión de pruebas
                    foreach ($postulacion->getMessages() as $message) {
                        echo $message;
                    }

                    return json_encode($postulacion);
                }

                //echo "--->".json_encode($postulacion->Convocatorias->Convocatorias->Categorias);
                $convocatoria_padre = $postulacion->Convocatorias->Convocatorias;

                //La convocatoria a la cual esta postulado tiene padre? si, es una categoria ;
                // La convocatoria padre tiene categorias iguales?
                //La convocatoria padre mismos_jurados_categoria?
                /*
                 * 01-06-2020
                 * Wilmer Mogollón
                 * Se ajusta el if eliminando una restricción
                 */
                //if ($convocatoria_padre && !$convocatoria_padre->diferentes_categorias && $convocatoria_padre->mismos_jurados_categorias) {
                if ($convocatoria_padre && $convocatoria_padre->mismos_jurados_categorias) {

                    // Start a transaction
                    $this->db->begin();

                    foreach ($convocatoria_padre->Categorias as $key => $categoria) {

                        if ($postulacion->Convocatorias->id != $categoria->id) {

                            $new_postulacion = new Juradospostulados();
                            $new_postulacion->propuesta = $postulacion->propuesta;
                            $new_postulacion->convocatoria = $categoria->id;
                            $new_postulacion->tipo_postulacion = $postulacion->tipo_postulacion;
                            $new_postulacion->estado = $postulacion->estado;
                            $new_postulacion->creado_por = $user_current["id"];
                            $new_postulacion->fecha_creacion = date("Y-m-d H:i:s");
                            $new_postulacion->active = $postulacion->active;
                            $new_postulacion->descripcion_evaluacion = $postulacion->descripcion_evaluacion;
                            $new_postulacion->aplica_perfil = $postulacion->aplica_perfil;
                            $new_postulacion->perfil = $postulacion->perfil;

                            // The model failed to save, so rollback the transaction
                            if ($new_postulacion->save() === false) {
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
        return "error_metodo" . $ex->getMessage() . json_encode($ex->getTrace());
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
        $total_evaluacion = 0;
        //$chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

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
                if ($juradospostulado->estado != 12 && $juradospostulado->estado != 13) {

                    $criterios = Convocatoriasrondascriterios::find(
                                    [
                                        "convocatoria_ronda=" . $request->getPost('ronda')
                                        . " AND active = true"
                                    ]
                    );

                    // Start a transaction
                    $this->db->begin();

                    $convocatoria_padre = $juradospostulado->Convocatorias->Convocatorias;

                    //para las convocatorias que tienen categorias y son los mismos jurados, se crea una vealuación por cada convocatoria
                    /*
                     * 01-06-2020
                     * Wilmer Mogollón
                     * Se ajusta el if eliminando una restricción
                     */
//                    if ($convocatoria_padre && !$convocatoria_padre->diferentes_categorias && $convocatoria_padre->mismos_jurados_categorias) {
                    if ($convocatoria_padre && $convocatoria_padre->mismos_jurados_categorias) {
                        $convocatorias = $convocatoria_padre->Categorias;

                        $conv = array();
                        foreach ($convocatorias as $key => $value) {
                            array_push($conv, $value->id);
                        }

                        $postulaciones = Juradospostulados::find(
                                        [
                                            'propuesta = ' . $juradospostulado->propuesta
                                            . ' AND convocatoria IN ({convocatorias:array})',
                                            'bind' => [
                                                'convocatorias' => $conv
                                            ]
                                        ]
                        );
                    } else {
                        $convocatorias = array($juradospostulado->Convocatorias);
                        $postulaciones = array($juradospostulado);
                    }

                    //  echo json_encode($convocatorias);
                    //  echo json_encode($postulaciones);

                    foreach ($postulaciones as $key => $juradospostulado) {

                        $evaluacion = Evaluacion::find(["postulado = " . $juradospostulado->id]);

                        //  echo json_encode($evaluacion);

                        if (count($evaluacion) <= 0) {

                            //return "no tiene evaluación";

                            foreach ($criterios as $key => $criterio) {

                                $evaluacion_criterio = new Evaluacion();
                                $evaluacion_criterio->propuesta = $juradospostulado->propuesta;
                                $evaluacion_criterio->criterio = $criterio->id;
                                $evaluacion_criterio->puntaje = $request->getPost((string) $criterio->id);
                                $evaluacion_criterio->creado_por = $user_current["id"];
                                $evaluacion_criterio->fecha_creacion = date("Y-m-d H:i:s");
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

                                array_push($response, $evaluacion_criterio->id);
                                $total_evaluacion = $total_evaluacion + $evaluacion_criterio->puntaje;
                            }//fin foreach
                        }//fin if

                        if (count($evaluacion) > 0) {
                            //  return "tiene evaluación";

                            foreach ($criterios as $key => $criterio) {

                                $evaluacion_criterio = Evaluacion::findFirst([
                                            " criterio = " . $criterio->id
                                            . " AND postulado = " . $juradospostulado->id
                                ]);
                                $evaluacion_criterio->puntaje = $request->getPost((string) $criterio->id);
                                $evaluacion_criterio->actualizado_por = $user_current["id"];
                                $evaluacion_criterio->fecha_actualizacion = date("Y-m-d H:i:s");

                                // The model failed to save, so rollback the transaction
                                if ($evaluacion_criterio->save() === false) {
                                    //Para auditoria en versión de pruebas
                                    foreach ($evaluacion_criterio->getMessages() as $message) {
                                        echo $message;
                                    }

                                    $this->db->rollback();
                                    return "error";
                                }

                                array_push($response, $evaluacion_criterio->id);
                                $total_evaluacion = $total_evaluacion + $evaluacion_criterio->puntaje;
                            }//fin foreach
                        }//fin if

                        $juradospostulado->total_evaluacion = $total_evaluacion;
                        $juradospostulado->actualizado_por = $user_current["id"];
                        $juradospostulado->fecha_actualizacion = date("Y-m-d H:i:s");

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
        //return "error_metodo";
        //Para auditoria en versión de pruebas
        return "error_metodo" . $ex->getMessage() . json_encode($ex->getTrace());
    }
}
);

// Crear registro
$app->post('/confirmar_evaluacion', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $response = array();
        $total_evaluacion = 0;
        //$chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

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
                if ($juradospostulado->estado != 12 && $juradospostulado->estado != 13) {

                    $criterios = Convocatoriasrondascriterios::find(
                                    [
                                        "convocatoria_ronda=" . $request->getPost('ronda')
                                        . " AND active = true"
                                    ]
                    );

                    // Start a transaction
                    $this->db->begin();

                    $convocatoria_padre = $juradospostulado->Convocatorias->Convocatorias;

                    //para las convocatorias que tienen categorias y son los mismos jurados, se crea una vealuación por cada convocatoria
                    /*
                     * 01-06-2020
                     * Wilmer Mogollón
                     * Se ajusta el if eliminando una restricción
                     */
//                    if ($convocatoria_padre && !$convocatoria_padre->diferentes_categorias && $convocatoria_padre->mismos_jurados_categorias) {
                    if ($convocatoria_padre && $convocatoria_padre->mismos_jurados_categorias) {
                        $convocatorias = $convocatoria_padre->Categorias;

                        $conv = array();
                        foreach ($convocatorias as $key => $value) {
                            array_push($conv, $value->id);
                        }

                        $postulaciones = Juradospostulados::find(
                                        [
                                            'propuesta = ' . $juradospostulado->propuesta
                                            . ' AND convocatoria IN ({convocatorias:array})',
                                            'bind' => [
                                                'convocatorias' => $conv
                                            ]
                                        ]
                        );
                    } else {
                        $convocatorias = array($juradospostulado->Convocatorias);
                        $postulaciones = array($juradospostulado);
                    }

                    //  echo json_encode($convocatorias);
                    //  echo json_encode($postulaciones);

                    foreach ($postulaciones as $key => $juradospostulado) {

                        $evaluacion = Evaluacion::find(["postulado = " . $juradospostulado->id]);

                        if (count($evaluacion) <= 0) {

                            foreach ($criterios as $key => $criterio) {

                                $evaluacion_criterio = new Evaluacion();
                                $evaluacion_criterio->propuesta = $juradospostulado->propuesta;
                                $evaluacion_criterio->criterio = $criterio->id;
                                $evaluacion_criterio->puntaje = $request->getPost((string) $criterio->id);
                                $evaluacion_criterio->creado_por = $user_current["id"];
                                $evaluacion_criterio->fecha_creacion = date("Y-m-d H:i:s");
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

                                array_push($response, $evaluacion_criterio->id);
                                $total_evaluacion = $total_evaluacion + $evaluacion_criterio->puntaje;
                            }//fin foreach
                        }//fin if

                        if (count($evaluacion) > 0) {
                            //  return "tiene evaluación";

                            foreach ($criterios as $key => $criterio) {

                                $evaluacion_criterio = Evaluacion::findFirst([
                                            " criterio = " . $criterio->id
                                            . " AND postulado = " . $juradospostulado->id
                                ]);
                                $evaluacion_criterio->puntaje = $request->getPost((string) $criterio->id);
                                $evaluacion_criterio->actualizado_por = $user_current["id"];
                                $evaluacion_criterio->fecha_actualizacion = date("Y-m-d H:i:s");

                                // The model failed to save, so rollback the transaction
                                if ($evaluacion_criterio->save() === false) {
                                    //Para auditoria en versión de pruebas
                                    foreach ($evaluacion_criterio->getMessages() as $message) {
                                        echo $message;
                                    }

                                    $this->db->rollback();
                                    return "error";
                                }

                                array_push($response, $evaluacion_criterio->id);
                                $total_evaluacion = $total_evaluacion + $evaluacion_criterio->puntaje;
                            }//fin foreach
                        }//fin if

                        $juradospostulado->total_evaluacion = $total_evaluacion;
                        $juradospostulado->estado = 12; //12	jurados	Evaluado
                        $juradospostulado->actualizado_por = $user_current["id"];
                        $juradospostulado->fecha_actualizacion = date("Y-m-d H:i:s");

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
        //return "error_metodo";
        //Para auditoria en versión de pruebas
        return "error_metodo" . $ex->getMessage() . json_encode($ex->getTrace());
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
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                $postulacion = Juradospostulados::findFirst($request->getPut('postulacion'));

                $postulacion->estado = 12; //12	jurados	Seleccionado
                $postulacion->actualizado_por = $user_current["id"];
                $postulacion->fecha_actualizacion = date("Y-m-d H:i:s");

                //caso 2
                if ($postulacion->convocatorias->tiene_categorias && !$postulacion->convocatorias->diferentes_categorias && $request->getPut('idcat')) {

                    //13	jurados	Seleccionado
                    $phql = 'UPDATE Juradospostulados SET estado = 13 , actualizado_por = ?0, fecha_actualizacion = ?1, convocatoria = ?2 WHERE id = ?3';

                    $result = $app->modelsManager->executeQuery(
                            $phql,
                            [
                                0 => $user_current["id"],
                                1 => date("Y-m-d H:i:s"),
                                2 => $request->getPut('idcat'),
                                3 => $postulacion->id,
                            ]
                    );


                    if ($result->success() === false) {
                        $messages = $result->getMessages();

                        foreach ($messages as $message) {
                            echo $message->getMessage();
                        }
                    }
                } else {

                    //se actualiza la postulación
                    if ($postulacion->update() === false) {
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
        return "error_metodo" . $ex->getMessage() . json_encode($ex->getTrace());
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
        if (isset($token_actual->id)) {
            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);
            $response = array();

            if ($user_current["id"]) {

                if ($request->get('idcat')) {

                    $convocatoria = Convocatorias::findFirst($request->get('idcat'));
                    //retorno el array en json
                    return json_encode($convocatoria);
                } elseif ($request->get('convocatoria')) {
                    $convocatoria = Convocatorias::findFirst($request->get('convocatoria'));
                    //retorno el array en json
                    return json_encode($convocatoria);
                } else {
                    return "error";
                }
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {

        //  echo "error_metodo";
        //Para auditoria en versión de pruebas
        return "error_metodo" . $ex->getMessage() . $ex->getTraceAsString();
    }
}
);

// Crea el registro de postulacion
$app->post('/new_postulacion', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //$chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);
        $contador = 0;
        $contador1 = 0;
        $contador2 = 0;

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $post = $app->request->getPost();

                $participante = Participantes::findFirst(
                                [
                                    " id = " . $request->getPut('participante')
                                ]
                );

                if ($participante) {
                    //9	jurados	Registrado
                    if ($participante->propuestas->estado == 9) {
                        return "error";
                    }

                    $postulaciones = $participante->propuestas->juradospostulados;

                    //Calcula el numero de postulaciones del jurado
                    //echo "cantidad-->".$postulaciones->count();
                    foreach ($postulaciones as $postulacion) {

                        //la convocatoria está activa y está publicada
                        if ($postulacion->convocatorias->active && $postulacion->convocatorias->estado == 5 && $postulacion->active && $postulacion->convocatorias->convocatoria_padre_categoria == null) {
                            $contador1++;
                        }
                    }

                    $postulaciones_categorias = Juradospostulados::query()
                            ->join("Convocatorias", "Convocatorias.id=Juradospostulados.convocatoria")
                            ->where("Juradospostulados.propuesta = " . $participante->propuestas->id)
                            ->andWhere("Juradospostulados.active")
                            ->andWhere("Convocatorias.active")
                            ->andWhere("Convocatorias.estado=5")
                            ->andWhere("Convocatorias.convocatoria_padre_categoria is not null")
                            ->groupBy("Convocatorias.convocatoria_padre_categoria")
                            ->columns("count(*)")
                            ->execute();



                    foreach ($postulaciones_categorias as $postulaciones_categoria) {
                        $contador2++;
                    }


                    $contador = $contador1 + $contador2;

                    $nummax = Tablasmaestras::findFirst(
                                    [
                                        " nombre = 'numero_maximo_postulaciones_jurado'"
                                    ]
                    );

                    //Controla el límite de postulaciones
                    //limite tabla maestra
                    if ($contador < (int) $nummax->valor) {

                        $juradopostulado = new Juradospostulados();
                        $juradopostulado->propuesta = $participante->propuestas->id;
                        $juradopostulado->estado = 9; //estado de la propuesta del jurado, 9 jurados	Registrado
                        $juradopostulado->creado_por = $user_current["id"];
                        $juradopostulado->fecha_creacion = date("Y-m-d H:i:s");
                        $juradopostulado->tipo_postulacion = 'Directa';
                        //  $juradopostulado->perfil = $request->getPut('perfil');
                        $juradopostulado->active = true;

                        $convocatoria = Convocatorias::findFirst($request->getPut('idregistro'));

                        //caso 3,la convocatoria tiene categoria y las categorias tienen diferente cronograma
                        if ($convocatoria->tiene_categorias && $convocatoria->diferentes_categorias) {

                            // $juradopostulado->convocatoria = $convocatoria->convocatoria_padre_categoria;
                            $juradopostulado->convocatoria = $convocatoria->id;
                        }//caso 2,la convocatoria tiene categoria y las categorias tienen igual cronograma
                        elseif ($convocatoria->tiene_categorias && !$convocatoria->diferentes_categorias) {

                            $juradopostulado->convocatoria = $convocatoria->id;
                        }//caso 1, la convocatoria  no tiene categoria
                        elseif (!$convocatoria->tiene_categorias) {

                            $juradopostulado->convocatoria = $request->getPut('idregistro');
                        }//end elseif (!$convocatoria->tiene_categorias)
                        //guardar registro
                        if ($juradopostulado->save() === false) {

                            //Para auditoria en versión de pruebas
                            /* foreach ($juradopostulado->getMessages() as $message) {
                              echo $message;
                              } */

                            return "error";
                        }

                        return $juradopostulado->id;
                    } else {

                        return "error_limite";
                    }
                }
            } else {

                return "acceso_denegado";
            }
        } else {

            return "error_token";
        }
    } catch (Exception $ex) {
        //echo "error_metodo"
        //Para auditoria en versión de pruebas
        echo "error_metodo " . $ex->getMessage() . $ex->getTraceAsString();
        return "error_metodo";
    }
}
);

/*
 * 06-07-2020
 * Wilmer Gustavo Mogollón Duque
 * Agrego metodo liberar postulaciones
 */

$app->put('/liberar_postulaciones/convocatoria/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $fase = '';

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Juradospreseleccion/liberar_postulaciones/convocatoria/{id:[0-9]+} ' . json_encode($request->getPut()) . '"',
                ['user' => '', 'token' => $request->getPut('token')]);
        $logger->close();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifica que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {


                if ($user_current["id"]) {

                    $convocatoria = Convocatorias::findFirst('id = ' . $id);



                    if (isset($convocatoria->id)) {//Si existe la convocatoria
                        $rondas = Convocatoriasrondas::find(
                                        [
                                            'convocatoria = ' . $convocatoria->id
                                            . ' AND active= true '
                                        ]
                        );

//                        return json_encode($rondas);

                        if (count($rondas) <= 0) {
                            return "error_rondas"; //Indica que no ha creado las rondas asociadas a esta convocatoria
                        } else {


                            $gruposconfirmados = true; //Se establece la variable $gruposconfirmados para determinar si están creados todos los grupos de evaluación

                            foreach ($rondas as $ronda) {
                                if ($ronda->grupoevaluador == null) {
                                    $gruposconfirmados = false;
                                } else {
                                    $grupo = Gruposevaluadores::findFirst(
                                                    [
                                                        'id = ' . $ronda->grupoevaluador
                                                    ]
                                    );
                                    $sin_confirmar = (Estados::findFirst(" tipo_estado = 'grupos_evaluacion' AND nombre = 'Sin confirmar' "))->id;

                                    if ($grupo->estado == $sin_confirmar) {
                                        $gruposconfirmados = false;
                                    }
                                }
                            }

//                        return json_encode($gruposconfirmados);
                            //Si los grupos evaluadores fueron creados y confirmados para todas las rondas, 
                            //entra a desactivar las postulaciones
                            if ($gruposconfirmados == true) {
                                // Start a transaction
                                $this->db->begin();

                                $postulaciones = Juradospostulados::find(
                                                [
                                                    'convocatoria = ' . $convocatoria->id
                                                    . ' AND active= true '
                                                ]
                                );

//                                $juradospostulados = Juradospostulados::find(
//                                                [
//                                                    " convocatoria = " . $request->get('convocatoria')
//                                                    . " AND active = true ",
//                                                    "order" => "aplica_perfil desc, total_evaluacion desc, estado desc"
//                                                ]
//                                );

                                $rechazada = (Estados::findFirst(" tipo_estado = 'jurado_notificaciones' AND nombre = 'Rechazada' "))->id;
                                $declinada = (Estados::findFirst(" tipo_estado = 'jurado_notificaciones' AND nombre = 'Declinada' "))->id;

                                foreach ($postulaciones as $postulacion) {

                                    /*
                                     * 28-10-2020
                                     * Wilmer gustavo Mogollón Duque
                                     * Se selecciona la última notificación de cada postulación
                                     */
                                    $notificacion = Juradosnotificaciones::maximum(
                                                    [
                                                        'column' => 'id',
                                                        'conditions' => "juradospostulado = " . $postulacion->id
//                                                        'conditions' => "juradospostulado = " . 5330
                                                    ]
                                    );

//                                    return json_encode($notificacion);

                                    if (isset($notificacion)) {

                                        if ($notificacion->estado == $rechazada || $notificacion->estado == $declinada) {
                                            $postulacion->active = false;
                                            $postulacion->fecha_actualizacion = date("Y-m-d H:i:s");
                                            $postulacion->actualizado_por = $user_current["id"];
                                        }
                                    } else {
                                        $postulacion->active = false;
                                        $postulacion->fecha_actualizacion = date("Y-m-d H:i:s");
                                        $postulacion->actualizado_por = $user_current["id"];
                                    }


                                    if (!$postulacion->save()) {

                                        //Para auditoria en versión de pruebas
                                        /* foreach ($evaluacion->getMessages() as $message) {
                                          echo $message;
                                          } */

                                        $logger->error('"token":"{token}","user":"{user}","message":"Juradospreseleccion/liberar_postulaciones/convocatoria/{id:[0-9]+} error:"' . $postulacion->getMessages(),
                                                ['user' => $user_current, 'token' => $request->getPut('token')]
                                        );
                                        $logger->close();

                                        $this->db->rollback();
                                        return "error";
                                    }
                                }

                                // Commit the transaction
                                $this->db->commit();
                                return "exito";
                            } else {
                                return "faltan_grupos"; //Indica que no puede liberar las postulaciones, pues no ha creado grupo evaluador para todas las rondas
                            }
                        }
                    } else {
                        $logger->error('"token":"{token}","user":"{user}","message":"Juradospreseleccion/liberar_postulaciones/convocatoria/{id:[0-9]+} error"',
                                ['user' => $user_current, 'token' => $request->getPut('token')]
                        );
                        $logger->close();

                        return "error";
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
        //return "error_metodo";
        //Para auditoria en versión de pruebas
        return "error_metodo" . $ex->getMessage() . json_encode($ex->getTrace());
    }
});


/*
 * 20-08-2020
 * Wilmer Gustavo Mogollón Duque
 * Agrego metodo liberar postulaciones
 */

$app->get('/search_info_inhabilidades', function () use ($app, $config) {

    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //  $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            //se establecen los valores del usuario
            $user_current = json_decode($token_actual->user_current, true);

            if ($user_current["id"]) {

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

                if ($request->get('postulacion') && $request->get('postulacion') == 'null') {

                    $participante = Participantes::findFirst($request->get('participante'));
                } elseif ($request->get('postulacion') && $request->get('postulacion') != 'null') {

                    $postulacion = Juradospostulados::findFirst($request->get('postulacion'));

                    if ($postulacion->perfil) {

                        $array["postulacion_perfil"] = Convocatoriasparticipantes::findFirst($postulacion->perfil);
                    }

                    $participante = $postulacion->propuestas->participantes;
                }

                if ($participante->id != null) {

                    //  $new_participante = clone $old_participante;

                    $participante->tipo_documento = Tiposdocumentos::findFirst($participante->tipo_documento)->descripcion;
                    $participante->sexo = Sexos::findFirst($participante->sexo)->nombre;
                    $participante->orientacion_sexual = Orientacionessexuales::findFirst($participante->orientacion_sexual)->nombre;
                    $participante->identidad_genero = Identidadesgeneros::findFirst($participante->identidad_genero)->nombre;
                    $participante->ciudad_residencia = Ciudades::findFirst($participante->ciudad_residencia)->nombre;
                    $participante->fecha_creacion = null;
                    $participante->participante_padre = null;

                    //Asigno el participante al array
                    $array["participante"] = $participante;
                    $array["propuesta_resumen"] = $participante->propuestas->resumen;
                    $array["propuesta_codigo"] = $participante->propuestas->codigo;
                    $array["propuesta_nombre"] = $participante->propuestas->nombre;
                    $array["propuesta_estado"] = Estados::findFirst($participante->propuestas->estado)->nombre;



                    //Consultamos las inhabilidades como contratistas                
                    $sql_contratistas = "
                    SELECT 
                            concat(p.numero_documento,' ',p.primer_nombre,' ',p.segundo_nombre,' ',p.primer_apellido,' ',p.segundo_apellido) AS participante,
                            concat(e.nombre) AS contratista
                    FROM Participantes AS p
                    INNER JOIN Entidadescontratistas AS ec ON REPLACE(REPLACE(TRIM(ec.numero_documento),'.',''),' ', '')=REPLACE(REPLACE(TRIM(p.numero_documento),'.',''),' ', '')
                    INNER JOIN Entidades AS e ON e.id=ec.entidad
                    WHERE (p.id=" . $participante->id . " OR p.participante_padre=" . $participante->id . ") AND p.tipo_documento<>7 AND ec.active=TRUE AND p.active=TRUE";

                    $contratistas = $app->modelsManager->executeQuery($sql_contratistas);

                    $array_contratistas = array();
                    foreach ($contratistas as $contratista) {
                        $array_contratistas[$contratista->participante][] = $contratista->contratista;
                    }
                    $array["contratistas"] = $array_contratistas;


                    //Variables html
                    $html_propuestas = "";
                    $html_propuestas_ganadoras = "";
                    $html_propuestas_jurados_seleccionados = "";

                    //Genero reporte de jurados seleccionados
                    $sql_jurados_seleccionado = "
                            SELECT 
                                    cp.nombre AS convocatoria,
                                    c.nombre AS categoria,
                                    c.anio as anio,
                                    par.tipo AS rol_participante,	
                                    jp.rol AS rol_jurado,
                                    par.numero_documento,
                                    concat(par.primer_nombre, ' ' ,par.segundo_nombre, ' ' ,par.primer_apellido, ' ' ,par.segundo_apellido ) AS participante,
                                    pro.codigo AS codigo_propuesta,
                                    e.nombre AS estado_de_la_postulacion
                            FROM Juradospostulados as jp
                                INNER JOIN Evaluadores ev ON jp.id=ev.juradopostulado 
                                    INNER JOIN Propuestas AS pro ON jp.propuesta = pro.id
                                    INNER join Participantes par on pro.participante = par.id
                                    INNER JOIN Convocatorias AS c ON jp.convocatoria = c.id
                                    LEFT JOIN Convocatorias as cp ON c.convocatoria_padre_categoria = cp.id
                                    LEFT JOIN Estados e ON jp.estado=e.id
                            WHERE 	
                                    jp.active=true AND
                                ev.active = true AND	                            
                                    REPLACE(REPLACE(TRIM(par.numero_documento),'.',''),' ', '') = '" . $participante->numero_documento . "'"
                            . " ORDER BY (c.anio) DESC"
                    ;

                    $jurados_seleccionados = $app->modelsManager->executeQuery($sql_jurados_seleccionado);


                    foreach ($jurados_seleccionados as $jurado) {
                        if ($jurado->convocatoria == "") {
                            $jurado->convocatoria = $jurado->categoria;
                            $jurado->categoria = "";
                        }


                        $html_propuestas_jurados_seleccionados = $html_propuestas_jurados_seleccionados . "<tr class='tr_jurados_seleccionados'>";
                        $html_propuestas_jurados_seleccionados = $html_propuestas_jurados_seleccionados . "<td>" . $jurado->anio . "</td>";
                        $html_propuestas_jurados_seleccionados = $html_propuestas_jurados_seleccionados . "<td>" . $jurado->convocatoria . "</td>";
                        $html_propuestas_jurados_seleccionados = $html_propuestas_jurados_seleccionados . '<td>' . $jurado->categoria . '</td>';
                        $html_propuestas_jurados_seleccionados = $html_propuestas_jurados_seleccionados . '<td>Jurado</td>';
                        $html_propuestas_jurados_seleccionados = $html_propuestas_jurados_seleccionados . '<td>' . $jurado->participante . '</td>';
                        $html_propuestas_jurados_seleccionados = $html_propuestas_jurados_seleccionados . '<td>Seleccionado</td>';
                        $html_propuestas_jurados_seleccionados = $html_propuestas_jurados_seleccionados . "</tr>";
                    }


                    $array["html_propuestas_jurados_seleccionados"] = $html_propuestas_jurados_seleccionados;


                    //Genero reporte de jurados proceso
                    $sql_jurados_proceso = "
                        SELECT 
                            c.anio AS anio,
                            en.nombre AS entidad,
                            cp.nombre AS convocatoria,
                            c.nombre AS categoria,	
                            par.tipo AS rol_participante,	
                            jp.rol AS rol_jurado,
                            par.numero_documento,
                            concat(par.primer_nombre, ' ' ,par.segundo_nombre, ' ' ,par.primer_apellido, ' ' ,par.segundo_apellido ) AS participante,
                            pro.codigo AS codigo_propuesta,
                            e.nombre AS estado_de_la_postulacion
                        FROM Juradospostulados as jp
                            INNER JOIN Propuestas AS pro ON jp.propuesta = pro.id
                            INNER JOIN Participantes AS par ON pro.participante = par.id
                            INNER JOIN Convocatorias AS c ON jp.convocatoria = c.id
                            INNER JOIN Entidades AS en ON en.id=c.entidad
                            LEFT JOIN Convocatorias AS cp ON c.convocatoria_padre_categoria = cp.id
                            LEFT JOIN Estados AS e ON jp.estado=e.id
                        WHERE 
                            jp.active=TRUE AND  
                            REPLACE(REPLACE(TRIM(par.numero_documento),'.',''),' ', '')=REPLACE(REPLACE(TRIM('" . $participante->numero_documento . "'),'.',''),' ', '')
                        ORDER BY 1,2,3,4,5,6,7,8";

                    $jurados_procesos = $app->modelsManager->executeQuery($sql_jurados_proceso);

                    foreach ($jurados_procesos as $jurado) {
                        if ($jurado->convocatoria == "") {
                            $jurado->convocatoria = $jurado->categoria;
                            $jurado->categoria = "";
                        }

                        if ($jurado->categoria != "") {
                            $jurado->categoria = "- " . $jurado->categoria;
                        }
                        

                        if (json_encode($jurado->anio) == date("Y")) {
                            $html_propuestas_jurados_proceso = $html_propuestas_jurados_proceso . "<tr>";
                            $html_propuestas_jurados_proceso = $html_propuestas_jurados_proceso . "<td>" . $jurado->anio . "</td>";
                            $html_propuestas_jurados_proceso = $html_propuestas_jurados_proceso . "<td>" . $jurado->entidad . "</td>";
                            $html_propuestas_jurados_proceso = $html_propuestas_jurados_proceso . "<td>" . $jurado->convocatoria . " " . $jurado->categoria . "</td>";
                            $html_propuestas_jurados_proceso = $html_propuestas_jurados_proceso . '<td>Jurado</td>';
                            $html_propuestas_jurados_proceso = $html_propuestas_jurados_proceso . '<td>' . $jurado->participante . '</td>';
                            $html_propuestas_jurados_proceso = $html_propuestas_jurados_proceso . '<td>' . $jurado->estado_de_la_postulacion . '</td>';
                            $html_propuestas_jurados_proceso = $html_propuestas_jurados_proceso . "</tr>";
                        }
                    }

                    $array["html_propuestas_jurados_proceso"] = $html_propuestas_jurados_proceso;


                    //Genero reporte personas naturales
                    $sql_pn = "
                            SELECT 
                                    vwp.convocatoria,
                                    vwp.codigo,
                                    vwp.nombre_propuesta,
                                    vwp.tipo_participante,
                                    vwp.representante,
                                    vwp.tipo_rol,
                                    vwp.rol,
                                    vwp.primer_nombre,
                                    vwp.segundo_nombre,
                                    vwp.primer_apellido,
                                    vwp.segundo_apellido,
                                    vwp.estado_propuesta                                
                            FROM Viewparticipantes AS vwp                                
                            WHERE vwp.tipo_participante <> 'Jurados' AND REPLACE(REPLACE(TRIM(vwp.numero_documento),'.',''),' ', '')  = '" . $participante->numero_documento . "'"
                    ;


                    $personas_naturales = $app->modelsManager->executeQuery($sql_pn);


//                    return json_encode($personas_naturales);
                    foreach ($personas_naturales as $pn) {

                        //Consulto la convocatoria -- Se ajusta la consulta, pues no siempre traia la información correcta.
                        $convocatoria_pn = Convocatorias::findFirst([" nombre = '" . $pn->convocatoria . "'"]);


                        //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
                        $nombre_convocatoria_pn = $convocatoria_pn->nombre;
                        $nombre_categoria_pn = "";
                        $anio_convocatoria_pn = $convocatoria_pn->anio;

                        if ($convocatoria_pn->convocatoria_padre_categoria > 0) {
                            $nombre_convocatoria_pn = $convocatoria_pn->getConvocatorias()->nombre;
                            $nombre_categoria_pn = $convocatoria_pn->nombre;
                            $anio_convocatoria_pn = $convocatoria_pn->getConvocatorias()->anio;
                        }

                        if ($anio_convocatoria_pn == $anio_convocatoria_pn) {
                            if ($pn->estado_propuesta == "Ganadora") {
                                $html_propuestas_ganadoras = $html_propuestas_ganadoras . "<tr class='tr_propuestas_ganadoras'>";
                                $html_propuestas_ganadoras = $html_propuestas_ganadoras . "<td>" . $anio_convocatoria_pn . "</td>";
                                $html_propuestas_ganadoras = $html_propuestas_ganadoras . "<td>" . $nombre_convocatoria_pn . "</td>";
                                $html_propuestas_ganadoras = $html_propuestas_ganadoras . "<td>" . $nombre_categoria_pn . "</td>";
                                $html_propuestas_ganadoras = $html_propuestas_ganadoras . "<td>" . $pn->tipo_rol . "</td>";
                                $html_propuestas_ganadoras = $html_propuestas_ganadoras . "<td>" . $pn->primer_nombre . " " . $pn->segundo_nombre . " " . $pn->primer_apellido . " " . $pn->segundo_apellido . "</td>";
                                $html_propuestas_ganadoras = $html_propuestas_ganadoras . "<td>" . $pn->codigo . "</td>";
                                $html_propuestas_ganadoras = $html_propuestas_ganadoras . "<td>" . $pn->estado_propuesta . "</td>";
                                $html_propuestas_ganadoras = $html_propuestas_ganadoras . "</tr>";
                            } else {
                                $html_propuestas = $html_propuestas . "<tr class='tr_propuestas'>";
                                $html_propuestas = $html_propuestas . "<td>" . $anio_convocatoria_pn . "</td>";
                                $html_propuestas = $html_propuestas . "<td>" . $nombre_convocatoria_pn . "</td>";
                                $html_propuestas = $html_propuestas . "<td>" . $nombre_categoria_pn . "</td>";
                                $html_propuestas = $html_propuestas . "<td>" . $pn->tipo_rol . "</td>";
                                $html_propuestas = $html_propuestas . "<td>" . $pn->primer_nombre . " " . $pn->segundo_nombre . " " . $pn->primer_apellido . " " . $pn->segundo_apellido . "</td>";
                                $html_propuestas = $html_propuestas . "<td>" . $pn->codigo . "</td>";
                                $html_propuestas = $html_propuestas . "<td>" . $pn->estado_propuesta . "</td>";
                                $html_propuestas = $html_propuestas . "</tr>";
                            }
                        }
                    }


                    $array["html_propuestas"] = $html_propuestas;
                    $array["html_propuestas_ganadoras"] = $html_propuestas_ganadoras;


                    //Genero reporte de jurados seleccionados en años anteriores
                    $sql_ganadores_anios_anteriores = "
                            SELECT 
                                    ga.*
                            FROM Ganadoresantes2020 as ga                                
                            WHERE 	
                                    ga.active=true AND                               
                                    REPLACE(REPLACE(TRIM(ga.numero_documento),'.',''),' ', '') = '" . $participante->numero_documento . "'                             
                            ORDER BY ga.anio DESC
                            ";

                    $ganadores_anios_anteriores = $app->modelsManager->executeQuery($sql_ganadores_anios_anteriores);

                    foreach ($ganadores_anios_anteriores as $ganador_anio_anterior) {
                        $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "<tr class='tr_ganador_anio_anterior'>";
                        $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "<td>" . $ganador_anio_anterior->anio . "</td>";
                        $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "<td>" . $ganador_anio_anterior->entidad . "</td>";
                        $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "<td>" . $ganador_anio_anterior->convocatoria . "</td>";
                        $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "<td>" . $ganador_anio_anterior->categoria . "</td>";
                        $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "<td>" . $ganador_anio_anterior->codigo_propuesta . " - " . $ganador_anio_anterior->estado_propuesta . " - " . $ganador_anio_anterior->nombre_propuesta . "</td>";
                        $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "<td>" . $ganador_anio_anterior->primer_nombre . " " . $ganador_anio_anterior->segundo_nombre . " " . $ganador_anio_anterior->primer_apellido . " " . $ganador_anio_anterior->segundo_apellido . "</td>";
                        $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "<td>" . $ganador_anio_anterior->tipo_participante . "</td>";
                        $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "<td>" . $ganador_anio_anterior->tipo_rol . "</td>";
                        $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "</tr>";
                    }

                    $array["html_ganadoras_anios_anteriores"] = $html_ganadoras_anios_anteriores;





                    //  $array["perfiles_covocatoria"] = Convocatoriasparticipantes::find("convocatoria = " $participante->propuestas->convocatorias->id
                } else {
                    $array["participante"] = new Participantes();
                }

                return json_encode($array);
            }
        } else {
            return "error_token";
        }
    } catch (Exception $ex) {

        //  echo "error_metodo";
        //Para auditoria en versión de pruebas
        echo "error_metodo" . $ex->getMessage() . json_encode($ex->getTrace());
    }
});


try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage();
}
?>
