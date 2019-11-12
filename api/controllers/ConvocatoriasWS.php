<?php

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

//Metodo que permite consultar toda la convocatoria con el fin de publicarla
$app->post('/search/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Si existe consulto la convocatoria y creo el objeto
        $convocatoria = Convocatorias::findFirst($id);
        $array_convocatoria["programa"] = $convocatoria->getProgramas()->nombre;
        $array_convocatoria["convocatoria"] = $convocatoria->nombre;
        $array_convocatoria["entidad"] = $convocatoria->getEntidades()->nombre;
        $array_convocatoria["descripcion"] = $convocatoria->descripcion;
        $array_convocatoria["estado"] = "Estado : " . $convocatoria->getEstados()->nombre;
        $array_convocatoria["linea"] = $convocatoria->getLineasestrategicas()->nombre;
        $array_convocatoria["area"] = $convocatoria->getAreas()->nombre;
        $array_convocatoria["tiene_categorias"] = $convocatoria->tiene_categorias;
        $array_convocatoria["diferentes_categorias"] = $convocatoria->diferentes_categorias;
        $array_convocatoria["numero_estimulos"] = $convocatoria->numero_estimulos;
        $array_convocatoria["valor_total_estimulos"] = "$ " . number_format($convocatoria->valor_total_estimulos, 0, '', '.');
        $array_convocatoria["bolsa_concursable"] = $convocatoria->bolsa_concursable;
        $array_convocatoria["descripcion_bolsa"] = $convocatoria->descripcion_bolsa;
        $array_convocatoria["objeto"] = $convocatoria->objeto;
        $array_convocatoria["no_pueden_participar"] = $convocatoria->no_pueden_participar;
        $array_convocatoria["derechos_ganadores"] = $convocatoria->derechos_ganadores;
        $array_convocatoria["deberes_ganadores"] = $convocatoria->deberes_ganadores;

        $tipo_convocatoria = "";

        //Valido que la convocatorias no tenga categorias            
        if ($convocatoria->tiene_categorias == false) {
            $tipo_convocatoria = "general";
            //Verifico si el bolsa y su dritribucion
            if ($convocatoria->bolsa_concursable) {
                //Si es Dinero
                if ($convocatoria->tipo_estimulo == 1) {
                    $array_convocatoria["numero_estimulos"] = count($convocatoria->getConvocatoriasrecursos([
                                'tipo_recurso = :tipo_recurso:',
                                'bind' => [
                                    'tipo_recurso' => 'Bolsa'
                                ],
                                'order' => 'orden ASC',
                    ]));
                }

                //Si es especie
                if ($convocatoria->tipo_estimulo == 2) {
                    $array_convocatoria["numero_estimulos"] = count($convocatoria->getConvocatoriasrecursos([
                                'tipo_recurso = :tipo_recurso:',
                                'bind' => [
                                    'tipo_recurso' => 'Especie'
                                ],
                                'order' => 'orden ASC',
                    ]));
                }

                //Si es mixta
                if ($convocatoria->tipo_estimulo == 3) {
                    $array_convocatoria["numero_estimulos"] = count($convocatoria->getConvocatoriasrecursos());
                }
            }

            //creo los listado de la convocatoria general
            $tabla_maestra = Tablasmaestras::findFirst("active=true AND nombre='listados'");
            $tipo_documento_listados = str_replace(",", "','", "'" . $tabla_maestra->valor . "'");
            $conditions = ['convocatoria' => $id, 'active' => true];
            $listados = Convocatoriasanexos::find(([
                        'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_documento IN (' . $tipo_documento_listados . ')',
                        'bind' => $conditions,
                        'order' => 'orden ASC',
            ]));
        } else {
            //Array para consultar las convocatorias generales
            $conditions = ['convocatoria_padre_categoria' => $id, 'active' => true];
            $categorias = Convocatorias::find([
                        'conditions' => 'convocatoria_padre_categoria=:convocatoria_padre_categoria: AND active=:active:',
                        'bind' => $conditions,
                        "order" => 'orden',
            ]);

            //Creo el in de categorias
            $array_categorias = "";
            foreach ($categorias as $categoria) {
                $array_categorias = $array_categorias . $categoria->id . ",";
            }
            $array_categorias = $array_categorias . $id;

            //Valido que la convocatorias tenga categorias generales
            if ($convocatoria->tiene_categorias == true && $convocatoria->diferentes_categorias == false) {

                $tipo_convocatoria = "general";

                //Se crea todo el array de las rondas de evaluacion
                foreach ($categorias as $categoria) {
                    $conditions = ['convocatoria' => $categoria->id, 'active' => true];
                    $consulta_rondas_evaluacion = Convocatoriasrondas::find(([
                                'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                                'bind' => $conditions,
                    ]));

                    foreach ($consulta_rondas_evaluacion as $ronda) {
                        $rondas_evaluacion[$ronda->id]["ronda"] = $ronda->numero_ronda;
                        $rondas_evaluacion[$ronda->id]["nombre"] = "<b>Categoría:</b> " . $categoria->nombre . " <br/><b>Ronda:</b> " . $ronda->nombre_ronda;
                        $rondas_evaluacion[$ronda->id]["descripcion"] = $ronda->descripcion_ronda;
                        $rondas_evaluacion[$ronda->id]["criterios"] = Convocatoriasrondascriterios::find(
                                        [
                                            "convocatoria_ronda = " . $ronda->id . "",
                                            "order" => 'orden'
                                        ]
                        );
                    }
                }

                //Se crea todo el array de las listados por categorias
                foreach ($categorias as $categoria) {
                    //consulto los tipos anexos listados
                    $tabla_maestra = Tablasmaestras::findFirst("active=true AND nombre='listados'");
                    $tipo_documento_listados = str_replace(",", "','", "'" . $tabla_maestra->valor . "'");
                    $conditions = ['convocatoria' => $categoria->id, 'active' => true];
                    $consulta_listados = Convocatoriasanexos::find(([
                                'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_documento IN (' . $tipo_documento_listados . ')',
                                'bind' => $conditions,
                                'order' => 'orden ASC',
                    ]));

                    foreach ($consulta_listados as $listado) {
                        $listados[$categoria->orden]["nombre"] = "<b>Categoría:</b> " . $categoria->nombre;
                        $listados[$categoria->orden]["listados"][] = $listado;
                    }
                }
            } else {
                //Valido que la convocatorias tenga categorias especiales            
                if ($convocatoria->tiene_categorias == true && $convocatoria->diferentes_categorias == true) {
                    $tipo_convocatoria = "especial";
                    if ($convocatoria->diferentes_categorias) {

                        //Recorro todas las categorias especiales
                        foreach ($categorias as $categoria) {

                            //Creo el array del estimulo
                            $categorias_estimulos[$categoria->id]["categoria"] = $categoria->nombre;
                            $categorias_estimulos[$categoria->id]["estimulos"][$categoria->id]["numero_estimulos"] = $categoria->numero_estimulos;
                            $categorias_estimulos[$categoria->id]["estimulos"][$categoria->id]["valor_total_estimulos"] = "$ " . number_format($categoria->valor_total_estimulos, 0, '', '.');
                            $categorias_estimulos[$categoria->id]["estimulos"][$categoria->id]["bolsa_concursable"] = $categoria->bolsa_concursable;
                            $categorias_estimulos[$categoria->id]["estimulos"][$categoria->id]["descripcion_bolsa"] = $categoria->descripcion_bolsa;
                            //Verifico si el bolsa y su dritribucion
                            if ($categoria->bolsa_concursable) {
                                //Si es Dinero
                                if ($categoria->tipo_estimulo == 1) {
                                    $categorias_estimulos[$categoria->id]["estimulos"][$categoria->id]["numero_estimulos"] = count($categoria->getConvocatoriasrecursos([
                                                'tipo_recurso = :tipo_recurso:',
                                                'bind' => [
                                                    'tipo_recurso' => 'Bolsa'
                                                ],
                                                'order' => 'orden ASC',
                                    ]));
                                }

                                //Si es especie
                                if ($categoria->tipo_estimulo == 2) {
                                    $categorias_estimulos[$categoria->id]["estimulos"][$categoria->id]["numero_estimulos"] = count($categoria->getConvocatoriasrecursos([
                                                'tipo_recurso = :tipo_recurso:',
                                                'bind' => [
                                                    'tipo_recurso' => 'Especie'
                                                ],
                                                'order' => 'orden ASC',
                                    ]));
                                }

                                //Si es mixta
                                if ($categoria->tipo_estimulo == 3) {
                                    $categorias_estimulos[$categoria->id]["estimulos"][$categoria->id]["numero_estimulos"] = count($categoria->getConvocatoriasrecursos());
                                }
                            }

                            //Consulto el cronograma por categoria
                            $conditions = ['convocatoria' => $categoria->id, 'active' => true];
                            $consulta_cronogramas = Convocatoriascronogramas::find(([
                                        'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                                        'bind' => $conditions,
                            ]));

                            //Creo el cronograma de las categorias especiales
                            foreach ($consulta_cronogramas as $evento) {
                                $cronogramas[$categoria->id]["categoria"] = $categoria->nombre;
                                $cronogramas[$categoria->id]["eventos"][$evento->id]["tipo_evento"] = $evento->getTiposeventos()->nombre;
                                if ($evento->getTiposeventos()->periodo) {
                                    $cronogramas[$categoria->id]["eventos"][$evento->id]["fecha"] = "desde " . date_format(new DateTime($evento->fecha_inicio), 'd/m/Y h:i:s a') . " hasta " . date_format(new DateTime($evento->fecha_fin), 'd/m/Y h:i:s a');
                                } else {
                                    $cronogramas[$categoria->id]["eventos"][$evento->id]["fecha"] = date_format(new DateTime($evento->fecha_inicio), 'd/m/Y h:i:s a');
                                }
                                $cronogramas[$categoria->id]["eventos"][$evento->id]["descripcion"] = $evento->descripcion;
                                $cronogramas[$categoria->id]["eventos"][$evento->id]["convocatoria"] = $categoria->id;
                            }


                            //Se crea todo el array de participantes por convocatoria
                            $consulta_participantes = Convocatoriasparticipantes::find(([
                                        'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_participante IN (1,2,3)',
                                        'bind' => $conditions,
                            ]));
                            foreach ($consulta_participantes as $participante) {
                                $participantes[$categoria->id]["categoria"] = $categoria->nombre;
                                $participantes[$categoria->id]["participantes"][$participante->id]["participante"] = $participante->getTiposParticipantes()->nombre;
                                $participantes[$categoria->id]["participantes"][$participante->id]["descripcion"] = $participante->descripcion_perfil;
                            }


                            //consulto los tipos anexos listados
                            $tabla_maestra = Tablasmaestras::findFirst("active=true AND nombre='listados'");
                            $tipo_documento_listados = str_replace(",", "','", "'" . $tabla_maestra->valor . "'");
                            $consulta_listados = Convocatoriasanexos::find(([
                                        'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_documento IN (' . $tipo_documento_listados . ')',
                                        'bind' => $conditions,
                                        'order' => 'orden ASC',
                            ]));
                            foreach ($consulta_listados as $listado) {
                                $listados[$categoria->orden]["nombre"] = "<b>Categoría:</b> " . $categoria->nombre;
                                $listados[$categoria->orden]["listados"][] = $listado;
                            }

                            //consulto los tipos anexos avisos
                            $tabla_maestra = Tablasmaestras::findFirst("active=true AND nombre='avisos'");
                            $tipo_documento_avisos = str_replace(",", "','", "'" . $tabla_maestra->valor . "'");
                            $consulta_avisos = Convocatoriasanexos::find(([
                                        'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_documento IN (' . $tipo_documento_avisos . ')',
                                        'bind' => $conditions,
                                        'order' => 'orden ASC',
                            ]));
                            foreach ($consulta_avisos as $listado) {
                                $avisos[$categoria->orden]["nombre"] = "<b>Categoría:</b> " . $categoria->nombre;
                                $avisos[$categoria->orden]["avisos"][] = $listado;
                            }

                            //Se crea todo el array de documentos administrativos y tecnicos
                            $consulta_documentos_administrativos = Convocatoriasdocumentos::find(([
                                        'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                                        'bind' => $conditions,
                                        'order' => 'orden ASC',
                            ]));
                            foreach ($consulta_documentos_administrativos as $documento) {
                                if ($documento->getRequisitos()->tipo_requisito == "Administrativos") {
                                    $documentos_administrativos[$categoria->id]["categoria"] = $categoria->nombre;
                                    $documentos_administrativos[$categoria->id]["administrativos"][$documento->id]["requisito"] = $documento->getRequisitos()->nombre;
                                    $documentos_administrativos[$categoria->id]["administrativos"][$documento->id]["descripcion"] = $documento->descripcion;
                                    $documentos_administrativos[$categoria->id]["administrativos"][$documento->id]["archivos_permitidos"] = json_decode($documento->archivos_permitidos);
                                    $documentos_administrativos[$categoria->id]["administrativos"][$documento->id]["tamano_permitido"] = $documento->tamano_permitido;
                                    $documentos_administrativos[$categoria->id]["administrativos"][$documento->id]["orden"] = $documento->orden;
                                    $documentos_administrativos[$categoria->id]["administrativos"][$documento->id]["convocatoria"] = $id;
                                }

                                if ($documento->getRequisitos()->tipo_requisito == "Tecnicos") {
                                    $documentos_tecnicos[$categoria->id]["categoria"] = $categoria->nombre;
                                    $documentos_tecnicos[$categoria->id]["administrativos"][$documento->id]["requisito"] = $documento->getRequisitos()->nombre;
                                    $documentos_tecnicos[$categoria->id]["administrativos"][$documento->id]["descripcion"] = $documento->descripcion;
                                    $documentos_tecnicos[$categoria->id]["administrativos"][$documento->id]["archivos_permitidos"] = json_decode($documento->archivos_permitidos);
                                    $documentos_tecnicos[$categoria->id]["administrativos"][$documento->id]["tamano_permitido"] = $documento->tamano_permitido;
                                    $documentos_tecnicos[$categoria->id]["administrativos"][$documento->id]["orden"] = $documento->orden;
                                    $documentos_tecnicos[$categoria->id]["administrativos"][$documento->id]["convocatoria"] = $id;
                                }
                            }

                            $consulta_rondas_evaluacion = Convocatoriasrondas::find(([
                                        'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                                        'bind' => $conditions,
                            ]));

                            foreach ($consulta_rondas_evaluacion as $ronda) {
                                $rondas_evaluacion[$ronda->id]["ronda"] = $ronda->numero_ronda;
                                $rondas_evaluacion[$ronda->id]["nombre"] = "<b>Categoría:</b> " . $categoria->nombre . " <br/><b>Ronda:</b> " . $ronda->nombre_ronda;
                                $rondas_evaluacion[$ronda->id]["descripcion"] = $ronda->descripcion_ronda;
                                $rondas_evaluacion[$ronda->id]["criterios"] = Convocatoriasrondascriterios::find(
                                                [
                                                    "convocatoria_ronda = " . $ronda->id . "",
                                                    "order" => 'orden'
                                                ]
                                );
                            }
                        }
                    }
                }
            }
        }


        if ($tipo_convocatoria == "general") {
            //Se crea todo el array del cronograma de actividades de la convocatoria simple            
            $conditions = ['convocatoria' => $id, 'active' => true];
            $consulta_cronogramas = Convocatoriascronogramas::find(([
                        'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                        'bind' => $conditions,
            ]));
            foreach ($consulta_cronogramas as $evento) {
                $cronogramas[$evento->id]["tipo_evento"] = $evento->getTiposeventos()->nombre;
                if ($evento->getTiposeventos()->periodo) {
                    $cronogramas[$evento->id]["fecha"] = "desde " . date_format(new DateTime($evento->fecha_inicio), 'd/m/Y h:i:s a') . " hasta " . date_format(new DateTime($evento->fecha_fin), 'd/m/Y h:i:s a');
                } else {
                    $cronogramas[$evento->id]["fecha"] = date_format(new DateTime($evento->fecha_inicio), 'd/m/Y h:i:s a');
                }
                $cronogramas[$evento->id]["descripcion"] = $evento->descripcion;
                $cronogramas[$evento->id]["convocatoria"] = $id;
            }

            //Se crea todo el array de participantes
            $conditions = ['convocatoria' => $id, 'active' => true];
            $consulta_participantes = Convocatoriasparticipantes::find(([
                        'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_participante IN (1,2,3)',
                        'bind' => $conditions,
            ]));
            foreach ($consulta_participantes as $participante) {
                $participantes[$participante->id]["participante"] = $participante->getTiposParticipantes()->nombre;
                $participantes[$participante->id]["descripcion"] = $participante->descripcion_perfil;
                $participantes[$participante->id]["convocatoria"] = $id;
            }

            //Se crea todo el array de documentos administrativos y tecnicos
            $conditions = ['convocatoria' => $id, 'active' => true];
            $consulta_documentos_administrativos = Convocatoriasdocumentos::find(([
                        'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                        'bind' => $conditions,
                        'order' => 'orden ASC',
            ]));
            foreach ($consulta_documentos_administrativos as $documento) {
                if ($documento->getRequisitos()->tipo_requisito == "Administrativos") {
                    $documentos_administrativos[$documento->id]["requisito"] = $documento->getRequisitos()->nombre;
                    $documentos_administrativos[$documento->id]["descripcion"] = $documento->descripcion;
                    $documentos_administrativos[$documento->id]["archivos_permitidos"] = json_decode($documento->archivos_permitidos);
                    $documentos_administrativos[$documento->id]["tamano_permitido"] = $documento->tamano_permitido;
                    $documentos_administrativos[$documento->id]["orden"] = $documento->orden;
                    $documentos_administrativos[$documento->id]["convocatoria"] = $id;
                }

                if ($documento->getRequisitos()->tipo_requisito == "Tecnicos") {
                    $documentos_tecnicos[$documento->id]["requisito"] = $documento->getRequisitos()->nombre;
                    $documentos_tecnicos[$documento->id]["descripcion"] = $documento->descripcion;
                    $documentos_tecnicos[$documento->id]["archivos_permitidos"] = json_decode($documento->archivos_permitidos);
                    $documentos_tecnicos[$documento->id]["tamano_permitido"] = $documento->tamano_permitido;
                    $documentos_tecnicos[$documento->id]["orden"] = $documento->orden;
                    $documentos_tecnicos[$documento->id]["convocatoria"] = $id;
                }
            }


            //consulto los tipos anexos avisos
            $tabla_maestra = Tablasmaestras::findFirst("active=true AND nombre='avisos'");
            $tipo_documento_avisos = str_replace(",", "','", "'" . $tabla_maestra->valor . "'");
            $conditions = ['convocatoria' => $id, 'active' => true];
            $avisos = Convocatoriasanexos::find(([
                        'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_documento IN (' . $tipo_documento_avisos . ')',
                        'bind' => $conditions,
                        'order' => 'orden ASC',
            ]));
        }

        //consulto los tipos anexos documentacion, aplica para las convocatorias sencillas, categorias generales y especiales
        $tabla_maestra = Tablasmaestras::findFirst("active=true AND nombre='documentacion'");
        $tipo_documento_documentacion = str_replace(",", "','", "'" . $tabla_maestra->valor . "'");
        $conditions = ['convocatoria' => $id, 'active' => true];
        $documentacion = Convocatoriasanexos::find(([
                    'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_documento IN (' . $tipo_documento_documentacion . ')',
                    'bind' => $conditions,
                    'order' => 'orden ASC',
        ]));


        //Creo todos los array del registro
        $array["convocatoria"] = $array_convocatoria;
        $array["categorias"] = $categorias;
        $array["cronogramas"] = $cronogramas;
        $array["participantes"] = $participantes;
        $array["categorias_estimulos"] = $categorias_estimulos;
        $array["documentos_administrativos"] = $documentos_administrativos;
        $array["documentos_tecnicos"] = $documentos_tecnicos;
        $array["rondas_evaluacion"] = $rondas_evaluacion;
        $array["listados"] = $listados;
        $array["documentacion"] = $documentacion;
        $array["avisos"] = $avisos;

        //Retorno el array
        echo json_encode($array);
    } catch (Exception $ex) {
        //retorno el array en json null
        echo "error_metodo" . $ex->getMessage();
    }
});

$app->post('/download_file', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

        echo $chemistry_alfresco->download($request->getPost('cod'));
    } catch (Exception $ex) {
        //retorno el array en json null
        echo "error_metodo";
    }
}
);

// Recupera todos los registros
$app->get('/all', function () use ($app) {
    try {

        //Instancio los objetos que se van a manejar
        $request = new Request();

        //Defino columnas para el orden desde la tabla html
        $columns = array(
            0 => 'c.anio',
            1 => 'e.nombre',
            2 => 'a.nombre',
            3 => 'l.nombre',
            4 => 'en.nombre',
            5 => 'c.nombre',
            6 => 'c.descripcion',
            7 => 'p.nombre',
            8 => 'es.nombre',
            9 => 'c.orden',
        );

        //Inicio el where de convocatorias
        $where_convocatorias .= " INNER JOIN Entidades AS e ON e.id=c.entidad";
        $where_convocatorias .= " INNER JOIN Programas AS p ON p.id=c.programa";
        $where_convocatorias .= " LEFT JOIN Areas AS a ON a.id=c.area";
        $where_convocatorias .= " LEFT JOIN Lineasestrategicas AS l ON l.id=c.linea_estrategica";
        $where_convocatorias .= " LEFT JOIN Enfoques AS en ON en.id=c.enfoque";
        $where_convocatorias .= " INNER JOIN Estados AS es ON es.id=c.estado";
        $where_convocatorias .= " LEFT JOIN Convocatorias AS cpad ON cpad.id=c.convocatoria_padre_categoria";
        $where_convocatorias .= " WHERE es.id IN (5, 6) AND c.active IN (true) ";


        //Condiciones para la consulta del select del buscador principal
        $estado_actual="";
        if (!empty($request->get("params"))) {
            foreach (json_decode($request->get("params")) AS $clave => $valor) {
                if ($clave == "nombre" && $valor != "") {
                    $where_convocatorias .= " AND ( UPPER(c.nombre) LIKE '%" . strtoupper($valor) . "%' ";
                    $where_convocatorias .= " OR UPPER(cpad.nombre) LIKE '%" . strtoupper($valor) . "%' )";
                }

                if ($valor != "" && $clave != "nombre" && $clave != "estado") {
                    $where_convocatorias = $where_convocatorias . " AND c." . $clave . " = " . $valor;
                }
                
                if ($clave == "estado") {
                    $estado_actual=$valor;                        
                }
                    
            }
        }

        //Duplico el where de convocatorias para categorias
        $where_categorias = $where_convocatorias;

        //Creo los WHERE especificios
        $where_convocatorias .= " AND c.convocatoria_padre_categoria IS NULL AND c.tiene_categorias=FALSE";
        $where_categorias .= " AND c.convocatoria_padre_categoria IS NOT NULL AND c.tiene_categorias=TRUE ";

        //Defino el sql del total y el array de datos
        $sqlTot = "SELECT count(*) as total FROM Convocatorias AS c";

        $sqlTotEstado = "SELECT c.estado,count(c.id) as total FROM Convocatorias AS c";

        $sqlConvocatorias = "SELECT "
                . "" . $columns[0] . " ,"
                . "" . $columns[1] . " AS entidad,"
                . "" . $columns[2] . " AS area,"
                . "" . $columns[3] . " AS linea_estrategica,"
                . "" . $columns[4] . " AS enfoque,"
                . "" . $columns[5] . " AS convocatoria , "
                . "" . $columns[6] . ","
                . "" . $columns[7] . " AS programa ,"
                . "" . $columns[8] . " AS estado ,"
                . "" . $columns[9] . " ,"
                . "cpad.nombre AS categoria ,"
                . "c.tiene_categorias ,"
                . "c.diferentes_categorias ,"
                . "cpad.id AS idd ,"
                . "c.id ,"
                . "c.estado AS id_estado ,"
                . "concat('<button type=\"button\" class=\"btn btn-warning cargar_cronograma\" data-toggle=\"modal\" data-target=\"#ver_cronograma\" title=\"',c.id,'\"><span class=\"glyphicon glyphicon-calendar\"></span></button>') as ver_cronograma,"
                . "concat('<button type=\"button\" class=\"btn btn-warning\" onclick=\"form_edit_page(2,',c.id,')\"><span class=\"glyphicon glyphicon-new-window\"></span></button>') as ver_convocatoria,concat('<input title=\"',c.id,'\" type=\"checkbox\" class=\"check_activar_',c.active,' activar_categoria\" />') as activar_registro  FROM Convocatorias AS c";

        $sqlCategorias = "SELECT "
                . "" . $columns[0] . " ,"
                . "" . $columns[1] . " AS entidad,"
                . "" . $columns[2] . " AS area,"
                . "" . $columns[3] . " AS linea_estrategica,"
                . "" . $columns[4] . " AS enfoque,"
                . "" . $columns[5] . " AS categoria , "
                . "" . $columns[6] . ","
                . "" . $columns[7] . " AS programa ,"
                . "" . $columns[8] . " AS estado ,"
                . "" . $columns[9] . " ,"
                . "cpad.nombre AS convocatoria ,"
                . "c.tiene_categorias ,"
                . "c.diferentes_categorias ,"
                . "cpad.id AS idd ,"
                . "c.id ,"
                . "c.estado AS id_estado ,"
                . "concat('<button type=\"button\" class=\"btn btn-warning cargar_cronograma\" data-toggle=\"modal\" data-target=\"#ver_cronograma\" title=\"',c.id,'\"><span class=\"glyphicon glyphicon-calendar\"></span></button>') as ver_cronograma,"
                . "concat('<button type=\"button\" class=\"btn btn-warning\" onclick=\"form_edit_page(2,',cpad.id,')\"><span class=\"glyphicon glyphicon-new-window\"></span></button>') as ver_convocatoria,concat('<input title=\"',cpad.id,'\" type=\"checkbox\" class=\"check_activar_',c.active,' activar_categoria\" />') as activar_registro  FROM Convocatorias AS c";


        //concatenate search sql if value exist
        if (isset($where_convocatorias) && $where_convocatorias != '') {

            $sqlTot .= $where_convocatorias;
            $sqlTotEstado .= $where_convocatorias;
            $sqlConvocatorias .= $where_convocatorias;
            $sqlCategorias .= $where_categorias;
        }

        //Concateno el orden y el limit para el paginador
        $sqlConvocatorias .= " ORDER BY " . $columns[$request->get('order')[0]['column']] . "   " . $request->get('order')[0]['dir'] . "  LIMIT " . $request->get('length') . " offset " . $request->get('start') . " ";
        $sqlCategorias .= " ORDER BY " . $columns[$request->get('order')[0]['column']] . "   " . $request->get('order')[0]['dir'] . "  LIMIT " . $request->get('length') . " offset " . $request->get('start') . " ";

        //Concateno el group by de estados
        $sqlTotEstado .= " GROUP BY 1";

        //Ejecutamos los sql de convocatorias y de categorias
        $array_convocatorias = $app->modelsManager->executeQuery($sqlConvocatorias);
        $array_categorias = $app->modelsManager->executeQuery($sqlCategorias);

        //ejecuto el total de registros actual
        $totalRecords = $app->modelsManager->executeQuery($sqlTot)->getFirst();

        $json_convocatorias = array();
        foreach ($array_convocatorias AS $clave => $valor) {
            
            $valor->estado_convocatoria = "<span class=\"span_" . $valor->estado . "\">" . $valor->estado . "</span>";
            if ($valor->tiene_categorias == false) {
                $fecha_actual = strtotime(date("Y-m-d H:i:s"), time());
                $fecha_cierre_real = Convocatoriascronogramas::findFirst("convocatoria=" . $valor->id . " AND tipo_evento = 12");
                $fecha_cierre = strtotime($fecha_cierre_real->fecha_fin, time());
                if ($fecha_actual > $fecha_cierre) {
                    $valor->id_estado = 52;
                    $valor->estado_convocatoria = "<span class=\"span_Cerrada\">Cerrada</span>";
                } else {
                    $fecha_apertura_real = Convocatoriascronogramas::findFirst("convocatoria=" . $valor->id . " AND tipo_evento = 11");
                    $fecha_apertura = strtotime($fecha_apertura_real->fecha_fin, time());
                    if ($fecha_actual < $fecha_apertura) {
                        $valor->estado_convocatoria = "<span class=\"span_Publicada\">Publicada</span>";
                    } else {
                        $valor->id_estado = 51;
                        $valor->estado_convocatoria = "<span class=\"span_Abierta\">Abierta</span>";
                    }
                }
            }
            //Realizo el filtro de estados
            if ($estado_actual=="") {                    
                $json_convocatorias[] = $valor;                    
            }
            else
            {
                if($estado_actual==$valor->id_estado)
                {                        
                    $json_convocatorias[] = $valor;
                }                    
            }
        }

        foreach ($array_categorias AS $clave => $valor) {
            $valor->estado_convocatoria = "<span class=\"span_" . $valor->estado . "\">" . $valor->estado . "</span>";
            if ($valor->tiene_categorias == true && $valor->diferentes_categorias == true) {
                $fecha_actual = strtotime(date("Y-m-d H:i:s"), time());
                $fecha_cierre_real = Convocatoriascronogramas::findFirst("convocatoria=" . $valor->id . " AND tipo_evento = 12");
                $fecha_cierre = strtotime($fecha_cierre_real->fecha_fin, time());
                if ($fecha_actual > $fecha_cierre) {
                    $valor->id_estado = 52;
                    $valor->estado_convocatoria = "<span class=\"span_Cerrada\">Cerrada</span>";
                } else {
                    $fecha_apertura_real = Convocatoriascronogramas::findFirst("convocatoria=" . $valor->id . " AND tipo_evento = 11");
                    $fecha_apertura = strtotime($fecha_apertura_real->fecha_fin, time());
                    if ($fecha_actual < $fecha_apertura) {
                        $valor->estado_convocatoria = "<span class=\"span_Publicada\">Publicada</span>";
                    } else {
                        $valor->id_estado = 51;
                        $valor->estado_convocatoria = "<span class=\"span_Abierta\">Abierta</span>";
                    }
                }
            }
            
            //Realizo el filtro de estados
            if ($estado_actual=="") {                    
                $json_convocatorias[] = $valor;                    
            }
            else
            {
                if($estado_actual==$valor->id_estado)
                {                        
                    $json_convocatorias[] = $valor;
                }                    
            }
        }

        //creo el array
        $json_data = array(
            "draw" => intval($request->get("draw")),
            "recordsTotal" => intval($totalRecords["total"]),
            "recordsFiltered" => intval($totalRecords["total"]),
            "dataEstados" => $app->modelsManager->executeQuery($sqlTotEstado),
            "data" => $json_convocatorias   // total data array            
        );
        //retorno el array en json
        echo json_encode($json_data);
    } catch (Exception $ex) {
        //retorno el array en json null
        echo json_encode($ex->getMessage());
    }
}
);

$app->get('/search_convocatorias', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar        
        $array = array();
        for ($i = date("Y"); $i >= 2016; $i--) {
            $array["anios"][] = $i;
        }
        $array["entidades"] = Entidades::find("active = true");
        $array["areas"] = Areas::find("active = true");
        $array["lineas_estrategicas"] = Lineasestrategicas::find("active = true");
        $array["programas"] = Programas::find("active = true");
        $array["enfoques"] = Enfoques::find("active = true");
        $array["estados"] = Estados::find(
                        array(
                            "tipo_estado = 'convocatorias' AND active = true AND id IN (5,6)",
                            "order" => "orden"
                        )
        );

        echo json_encode($array);
    } catch (Exception $ex) {
        //retorno el array en json null
        echo "error_metodo";
    }
}
);

$app->post('/cargar_cronograma/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Consulto el cronograma de la convocatoria
        $conditions = ['convocatoria' => $id, 'active' => true];
        $consulta_cronogramas = Convocatoriascronogramas::find(([
                    'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                    'bind' => $conditions,
                    'order' => 'fecha_inicio',
        ]));

        //Creo el cronograma        
        foreach ($consulta_cronogramas as $evento) {
            //Solo cargo los eventos publicos
            if($evento->getTiposeventos()->publico)
            {
                $array_evento=array();
                $array_evento["tipo_evento"] = $evento->getTiposeventos()->nombre;
                if ($evento->getTiposeventos()->periodo) {
                    $array_evento["fecha"] = "desde " . date_format(new DateTime($evento->fecha_inicio), 'd/m/Y h:i:s a') . " hasta " . date_format(new DateTime($evento->fecha_fin), 'd/m/Y h:i:s a');                
                } else {
                    $array_evento["fecha"] = date_format(new DateTime($evento->fecha_inicio), 'd/m/Y h:i:s a');                
                }
                $array_evento["descripcion"] = $evento->descripcion;            
                $cronogramas[]=$array_evento;
            }
        }

        echo json_encode($cronogramas);
    } catch (Exception $ex) {
        //retorno el array en json null
        echo "error_metodo";
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