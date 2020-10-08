<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

use Phalcon\Loader;
use Phalcon\Mvc\Micro;
use Phalcon\Di\FactoryDefault;
use Phalcon\Db\Adapter\Pdo\Postgresql as DbAdapter;
use Phalcon\Config\Adapter\Ini as ConfigIni;
use Phalcon\Http\Request;
use Phalcon\Logger\Adapter\File as FileAdapter;
use Phalcon\Logger\Formatter\Line;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpWord\Style\Language;
use Phalcon\Mvc\Model\Query;
use Phalcon\Db\RawValue;

// Definimos algunas rutas constantes para localizar recursos
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH);

//Defino las variables principales de conexion
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

$app->get('/holamundo', function () use ($app, $config, $logger) {
    echo "hola mundo";
});

//$app->get('/evaluacionpropuestas/ronda/{ronda:[0-9]+}', function ($ronda) use ($app, $config) {
$app->post('/genera_archivo', function () use ($app, $config, $logger) {

    try {
        require_once("../library/phpword/autoload.php");

//        require_once "vendor/autoload.php";


        $documento = new \PhpOffice\PhpWord\PhpWord();
        $propiedades = $documento->getDocInfo();
        $propiedades->setCreator("SICON");
        $propiedades->setCompany("SICON");
        $propiedades->setTitle("Reporte de planillas de evaluación");
        $propiedades->setDescription("Este es un documento para mostrar planillas de evaluación");
        $propiedades->setCategory("Reportes");
        $propiedades->setLastModifiedBy("SICON");
//$propiedades->setCreated(mktime());
//$propiedades->setModified(mktime());
        $propiedades->setSubject("Asunto");
        $propiedades->setKeywords("documento, php, word");

        $seccion = $documento->addSection();

        // Define styles
        $fontStyle12 = array('spaceAfter' => 60, 'size' => 12);
        $fontStyle10 = array('size' => 10);
        $centrado = 'pStyle';
        $documento->addParagraphStyle($centrado, array('textAlignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER, 'spaceAfter' => 100));
        $documento->addParagraphStyle('centrado', array('align' => 'center'));
        $documento->addTitleStyle(null, array('size' => 22, 'bold' => true));
        $documento->addTitleStyle(1, array('size' => 20, 'color' => '333333', 'bold' => true));
        $documento->addTitleStyle(2, array('size' => 16, 'color' => '666666'));
        $documento->addTitleStyle(3, array('size' => 12, 'name' => 'Times', 'bold' => true, $centrado));
        $documento->addTitleStyle(4, array('size' => 12, 'bold' => true));


        $seccion->addImage('../resources/img/logo-secretaria-cultura.png', array('width' => 70, 'height' => 70, 'alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER));

        $seccion->addTextBreak(1);

        // Add text elements
        $seccion->addTitle('IDPC', 3, array('textAlignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER, 'spaceAfter' => 100));
        $seccion->addTitle('PROGRAMA DISTRITAL DE ESTÍMULOS-PDE', 3);
        $seccion->addTitle('PLANILLA DE EVALUACIÓN', 3);
        $seccion->addTitle('Beca para la visibilización y apropiación del Patrimonio Cultural Inmaterial de grupos étnicos presentes en Bogotá', 3);
        $seccion->addTitle('Problación Negra, Afrodescendiente y Palenquera', 3);

        $seccion->addTextBreak(2);



        //consulta

        $phql = 'SELECT
                  distinct (p.id), p.*
               FROM
                  Propuestas p
                  inner join Evaluacionpropuestas ep ON p.id = ep.propuesta
              WHERE
              ep.ronda = 312';

        $propuestas = $this->modelsManager->createQuery($phql)->execute();




        //por cada propuesta evaluada

        foreach ($propuestas as $propuesta) {

//            echo json_encode($propuesta);

            $participantes = Participantes::findFirst('id = ' . $propuesta->p->participante);


            $seccion->addTitle('INFORMACIÓN DE LA PROPUESTA:', 4);
            $seccion->addTitle('Código de propuesta: ' . $propuesta->p->codigo, 4);
            $seccion->addTitle('Nombre Participante: ' . $participantes->primer_nombre . " "
                    . $participantes->segundo_nombre . " "
                    . $participantes->primer_apellido . " "
                    . $participantes->primer_apellido, 4);
            $seccion->addTitle('Nombre Propuesta: ' . $propuesta->p->nombre, 4);


            $evaluaciones = Evaluacionpropuestas::find(
                            [
                                ' propuesta = ' . $propuesta->p->id
                                . ' AND ronda = 312'
                            ]
            );




            foreach ($evaluaciones as $evaluacion) {

                $criterios = Convocatoriasrondascriterios::find(
                                [
                                    'convocatoria_ronda = 312'
                                    . ' AND active= true',
                                    'order' => 'orden ASC'
                                ]
                );

                $evaluador = Evaluadores::findFirst('id = ' . $evaluacion->evaluador);

                $juradopostulado = Juradospostulados::findFirst('id = ' . $evaluador->juradopostulado);
//                $fecha_eval = Evaluacionpropuestas::findFirst('evaluador = ' . $evaluador->juradopostulado);



                $seccion->addTextBreak(2);
                $seccion->addTitle('INFORMACIÓN DE LA EVALUACIÓN', 3);

                $seccion->addTitle('Nombre Jurado: ' . $juradopostulado->Propuestas->Participantes->primer_nombre . " "
                        . $juradopostulado->Propuestas->Participantes->segundo_nombre . " "
                        . $juradopostulado->Propuestas->Participantes->primer_apellido . " "
                        . $juradopostulado->Propuestas->Participantes->segundo_apellido, 4);
                $seccion->addTitle('Total evaluación: ' . $evaluacion->total, 4);
                $seccion->addTitle('Fecha de evaluación: ', 4);



                $seccion->addTextBreak(1);
                $seccion->addTitle('CRITERIOS DE EVALUACIÓN', 3);



                $fancyTableStyleName = 'Fancy Table';
                $cellRowSpan = array('vMerge' => 'restart', 'valign' => 'center', 'bgColor' => 'FFFF00');
                $fancyTableStyle = array('borderSize' => 10, 'borderColor' => '999999', 'cellMargin' => 80, 'alignment' => \PhpOffice\PhpWord\SimpleType\JcTable::CENTER, 'cellSpacing' => 50);
                $fancyTableFirstRowStyle = array('borderBottomSize' => 18, 'borderBottomColor' => '0000FF', 'bgColor' => '66BBFF');
                $fancyTableCellStyle = array('valign' => 'center');
                $fancyTableCellBtlrStyle = array('valign' => 'center', 'textDirection' => \PhpOffice\PhpWord\Style\Cell::TEXT_DIR_BTLR);
                $fancyTableFontStyle = array('bold' => true);
                $documento->addTableStyle($fancyTableStyleName, $fancyTableStyle, $fancyTableFirstRowStyle, $cellRowSpan);
                $table = $seccion->addTable($fancyTableStyleName);
                $table->addRow(900);
                $table->addCell(500, $fancyTableCellStyle)->addText('No', $fancyTableFontStyle, $cellRowSpan);
                $table->addCell(3500, $fancyTableCellStyle)->addText('Criterio', $fancyTableFontStyle, $cellRowSpan);
                $table->addCell(1000, $fancyTableCellStyle)->addText('Puntaje máximo', $fancyTableFontStyle);
                $table->addCell(4000, $fancyTableCellStyle)->addText('Observaciones', $fancyTableFontStyle);
                $table->addCell(1000, $fancyTableCellStyle)->addText('Calificación', $fancyTableFontStyle);

                $cont = 0;

                foreach ($criterios as $criterio) {
                    $cont++;

                    $evaluacioncriterio = Evaluacioncriterios::findFirst(
                                    [
                                        'evaluacionpropuesta = ' . $evaluacion->id
                                        . ' AND criterio = ' . $criterio->id
                                        . ' AND active= true'
                                    ]
                    );

                    $table->addRow();
                    $table->addCell(500)->addText($cont);
                    $table->addCell(3500)->addText($criterio->descripcion_criterio);
                    $table->addCell(1000)->addText($criterio->puntaje_maximo);
                    $table->addCell(4000)->addText($evaluacioncriterio->observacion);
                    $table->addCell(1000)->addText($evaluacioncriterio->puntaje);
                }

                $seccion->addTextBreak(2);
            };
        }



# Para que no diga que se abre en modo de compatibilidad
        $documento->getCompatibility()->setOoxmlVersion(15);
# Idioma español de México
        $documento->getSettings()->setThemeFontLang(new Language("ES-MX"));
# Enviar encabezados para indicar que vamos a enviar un documento de Word
        $nombre = "libro.docx";
        header("Content-Description: File Transfer");
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Expires: 0');


        $objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($documento, "Word2007");
# Y lo enviamos a php://output
        $objWriter->save("php://output");
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_listado_entidades_convocatorias_estado_xls al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});


/*
 * 11-06-2020
 * Wilmer Gustavo Mogollón Duque
 * Se agrega método acta_recomendacion_preseleccionados para generar acte de recomendación de preseleccionados
 */

//$app->get('/evaluacionpropuestas/ronda/{ronda:[0-9]+}', function ($ronda) use ($app, $config) {
$app->get('/acta_recomendacion_preseleccionados/ronda/{ronda:[0-9]+}', function ($ronda) use ($app, $config, $logger) {

    try {
        require_once("../library/phpword/autoload.php");

//        require_once "vendor/autoload.php";
//        $request = new Request();


        $documento = new \PhpOffice\PhpWord\PhpWord();
        $propiedades = $documento->getDocInfo();
        $propiedades->setCreator("SICON");
        $propiedades->setCompany("SICON");
        $propiedades->setTitle("Acta de recomendación de ganadores");
        $propiedades->setDescription("Este es un documento para Acta de recomendación de ganadores");
        $propiedades->setCategory("Actas");
        $propiedades->setLastModifiedBy("SICON");
//$propiedades->setCreated(mktime());
//$propiedades->setModified(mktime());
        $propiedades->setSubject("Asunto");
        $propiedades->setKeywords("documento, php, word");

        $seccion = $documento->addSection(array("marginLeft" => 600, "marginRight" => 600, "marginTop" => 600, "marginBottom" => 600));

        // Define styles
        $fontStyle12 = array('spaceAfter' => 60, 'size' => 12);
        $fontStyle10 = array('size' => 10);
        $fontStyle9 = array('size' => 9);
        $centrado = 'pStyle';
        $documento->addParagraphStyle($centrado, array('textAlignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER, 'spaceAfter' => 100));
        $documento->addParagraphStyle('centrado', array('align' => 'center'));
        $documento->addTitleStyle(null, array('size' => 22, 'bold' => true));
        $documento->addTitleStyle(1, array('size' => 20, 'color' => '333333', 'bold' => true));
        $documento->addTitleStyle(2, array('size' => 16, 'color' => '666666'));
        $documento->addTitleStyle(3, array('size' => 12, 'name' => 'Times', 'bold' => true, $centrado));
        $documento->addTitleStyle(4, array('size' => 12, 'bold' => true));





        $seccion->addImage('../resources/img/logo-secretaria-cultura.png', array('width' => 70, 'height' => 70, 'alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER));

        $seccion->addTextBreak(1);



        /*
         * Creo el objeto para traer los datos de la convocatoria
         */

        $convocatoriaronda = Convocatoriasrondas::findFirst(
                        [
                            'id = ' . $ronda
                            . ' AND active= true'
                        ]
        );

        /*
         * Creo el objeto para traer los datos de la convocatoria
         */

        $convocatoria = Convocatorias::findFirst(
                        [
                            'id = ' . $convocatoriaronda->convocatoria
                            . ' AND active= true'
                        ]
        );

        $programa = Programas::findFirst(['id=' . $convocatoria->programa]);


        if ($convocatoria->convocatoria_padre_categoria == null) {
            $nombrec = $convocatoria->nombre;
        } else {
            $convocatoriapadre = Convocatorias::findFirst(
                            [
                                'id = ' . $convocatoria->convocatoria_padre_categoria
                                . ' AND active= true'
                            ]
            );
            $nombrec = $convocatoriapadre->nombre . " - " . $convocatoria->nombre;
        }

        $entidad = Entidades::findFirst(
                        [
                            'id = ' . $convocatoria->entidad
                            . ' AND active= true'
                        ]
        );


        // Add text elements
        $seccion->addText($entidad->nombre, array('bold' => true, "size" => 12), array('align' => 'center'));
        $seccion->addText($programa->nombre, array('bold' => true, "size" => 12), array('align' => 'center'));

        $seccion->addTextBreak(1);

        $seccion->addText($nombrec, array('bold' => true, "size" => 12), array('align' => 'center'));

        $seccion->addTextBreak(2);

        if ($convocatoria->estado == 43) {
            $seccion->addText('ACTA DE CONVOCATORIA DESIERTA', array('bold' => true, "size" => 12), array('align' => 'center'));
        } else {
            //Pregunto que tipo de acta voy a imprimir
            if ($convocatoriaronda->tipo_acta == 'Preselección') {
                $seccion->addText('ACTA DE RECOMENDACIÓN DE PRESELECCIONADOS', array('bold' => true, "size" => 12), array('align' => 'center'));
            } else {
                if ($convocatoriaronda->tipo_acta == 'Ganadores') {
                    $seccion->addText('ACTA DE RECOMENDACIÓN DE GANADORES', array('bold' => true, "size" => 12), array('align' => 'center'));
                }
            }
        }





        $seccion->addTextBreak(2);

        //consulta

        $phql = 'SELECT
                  distinct (p.id), p.*
               FROM
                  Propuestas p
                  inner join Evaluacionpropuestas ep ON p.id = ep.propuesta
              WHERE
              ep.ronda = ' . $ronda
                . 'LIMIT 1';

        $rs = $this->modelsManager->createQuery($phql)->execute();


        $seccion->addText('El día ' . $convocatoriaronda->fecha_deliberacion . ' deliberó en la ciudad de Bogotá el jurado integrado por:');

        foreach ($rs as $row) {
            //echo json_encode($rs);

            $evaluacionpropuestas = Evaluacionpropuestas::find(
                            [
                                ' propuesta = ' . $row->p->id
                                . ' AND ronda = ' . $ronda
                            ]
            );

            $seccion->addTextBreak(2);

            $fancyTableStyleName = 'Fancy Table';
            $cellRowSpan = array('vMerge' => 'restart', 'valign' => 'center', 'bgColor' => 'FFFF00');
            $fancyTableStyle = array('borderSize' => 1, 'borderColor' => '999999', 'alignment' => \PhpOffice\PhpWord\SimpleType\JcTable::CENTER); //cambiar
            $fancyTableFirstRowStyle = array('alignment' => \PhpOffice\PhpWord\SimpleType\JcTable::CENTER);
            $fancyTableCellStyle = array('valign' => 'center', 'bgColor' => 'dedbda',);
            $fancyTableCellBtlrStyle = array('valign' => 'center', 'textDirection' => \PhpOffice\PhpWord\Style\Cell::TEXT_DIR_BTLR);
            $fancyTableFontStyle = array('bold' => true);
            $documento->addTableStyle($fancyTableStyleName, $fancyTableStyle, $fancyTableFirstRowStyle, $cellRowSpan);
            $table = $seccion->addTable($fancyTableStyleName);
            $table->addRow(900);
            $table->addCell(5000, $fancyTableCellStyle)->addText('NOMBRE Y APELLIDOS', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);
            $table->addCell(1000, $fancyTableCellStyle)->addText('TD', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);
            $table->addCell(4500, $fancyTableCellStyle)->addText('NÚMERO DE DOCUMENTO', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);

            $evaluadores = Evaluadores::find(['grupoevaluador =' . $convocatoriaronda->grupoevaluador]);

            foreach ($evaluadores as $evaluador) {

                $juradopostulado = Juradospostulados::findFirst('id = ' . $evaluador->juradopostulado);
                $tipodocumento = Tiposdocumentos::findFirst('id = ' . $juradopostulado->Propuestas->Participantes->tipo_documento);

                $table->addRow();
                $table->addCell(5000)->addText($juradopostulado->Propuestas->Participantes->primer_nombre . " "
                        . $juradopostulado->Propuestas->Participantes->segundo_nombre . " "
                        . $juradopostulado->Propuestas->Participantes->primer_apellido . " "
                        . $juradopostulado->Propuestas->Participantes->segundo_apellido, $fontStyle9);
                $table->addCell(1000)->addText($tipodocumento->nombre, $fontStyle9);
                $table->addCell(4500)->addText($juradopostulado->Propuestas->Participantes->numero_documento, $fontStyle9);
            }
        }









        $seccion->addTextBreak(2);





        /*
         * Creo el objeto para traer la fecha de cierre de la convocatoria
         */



        if ($convocatoria->convocatoria_padre_categoria != null && $convocatoria->Convocatorias->diferentes_categorias == false) {
            //Acá determino si es tipo 2 
            $idc = $convocatoria->convocatoria_padre_categoria;
        } else {

            $idc = $convocatoria->id;
        }

        $convocatoriacronograma = Convocatoriascronogramas::findFirst(
                        [
                            'convocatoria = ' . $idc
                            . ' AND tipo_evento= 12'
                            . ' AND active= true'
                        ]
        );


        /*
         * Creo los objetos para traer contar rl total de propuestas y las propuestas habilitadas
         */

        $totalpropuestaconvocatoria = Propuestas::find(
                        [
                            'convocatoria = ' . $convocatoria->id
                            . ' AND estado not in (20,7)'//Para no traer anuladas y guardadas - no inscritas
                            . ' AND active= true'
                        ]
        );


        $propuestashabilitadas = Propuestas::find(
                        [
                            'convocatoria = ' . $convocatoria->id
                            . ' AND active= true'
                            . ' AND estado in (24,33)'
                        ]
        );



        if ($convocatoria->estado == 43) {
            $seccion->addText('La reunión se realizó con el propósito de evaluar las propuestas participantes en la convocatoria '
                    . $nombrec . ' del ' . $programa->nombre);
        } else {

            //Pregunto que tipo de acta voy a imprimir
            if ($convocatoriaronda->tipo_acta == 'Preselección') {
                $seccion->addText('La reunión se realizó con el propósito de preseleccionar las propuestas participantes en la convocatoria '
                        . $nombrec . ' del ' . $programa->nombre);
            } else {
                if ($convocatoriaronda->tipo_acta == 'Ganadores') {
                    $seccion->addText('La reunión se realizó con el propósito de concluir el proceso de evaluación técnica de las propuestas participantes en la convocatoria '
                            . $nombrec . ' del ' . $programa->nombre);
                }
            }
        }










        $seccion->addTextBreak(1);

        $seccion->addTitle('PRIMERO. Inscritos y habilitados', 3);
        $seccion->addText('Al cierre de la convocatoria el día ' . $convocatoriacronograma->fecha_fin . ' se inscribieron en total ' . $totalpropuestaconvocatoria->count() . ' propuestas, de las cuales ' . $propuestashabilitadas->count() . ' quedaron habilitadas para ser evaluadas por parte del jurado.
        ');

        $seccion->addTextBreak(1);

        $seccion->addTitle('SEGUNDO. Criterios de evaluación', 3);
        $seccion->addText('La evaluación se realizó teniendo en cuenta los criterios establecidos en las condiciones específicas de la convocatoria:');

        $seccion->addTextBreak(1);

        /*
         * Creo el objeto para listar los criterios
         */

        $criterios = Convocatoriasrondascriterios::find(
                        [
                            'convocatoria_ronda = ' . $ronda
                            . ' AND active= true',
                            'order' => 'orden ASC'
                        ]
        );

        $fancyTableStyleName = 'Fancy Table';
        $cellRowSpan = array('vMerge' => 'restart', 'valign' => 'center', 'bgColor' => 'FFFF00');
        $fancyTableStyle = array('borderSize' => 1, 'borderColor' => '999999', 'alignment' => \PhpOffice\PhpWord\SimpleType\JcTable::CENTER); //cambiar
        $fancyTableFirstRowStyle = array('alignment' => \PhpOffice\PhpWord\SimpleType\JcTable::CENTER);
        $fancyTableCellStyle = array('valign' => 'center', 'bgColor' => 'dedbda',);
        $fancyTableCellBtlrStyle = array('valign' => 'center', 'textDirection' => \PhpOffice\PhpWord\Style\Cell::TEXT_DIR_BTLR);
        $fancyTableFontStyle = array('bold' => true);
        $documento->addTableStyle($fancyTableStyleName, $fancyTableStyle, $fancyTableFirstRowStyle, $cellRowSpan);
        $table = $seccion->addTable($fancyTableStyleName);
        $table->addRow(900);
        $table->addCell(500, $fancyTableCellStyle)->addText('No', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);
        $table->addCell(7500, $fancyTableCellStyle)->addText('CRITERIO', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);
        $table->addCell(2500, $fancyTableCellStyle)->addText('PUNTAJE', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);



        $cont = 0;
        $suma_criterios = 0;
        foreach ($criterios as $criterio) {
            $cont++;
            $table->addRow();
            $table->addCell(500)->addText($cont);
            $table->addCell(7500)->addText($criterio->descripcion_criterio);
            $table->addCell(2500)->addText($criterio->puntaje_maximo);
            $suma_criterios = $suma_criterios + $criterio->puntaje_maximo;
        }
        $table->addRow();
        $table->addCell(500)->addText();
        $table->addCell(7500)->addText('Total');
        $table->addCell(2500)->addText($suma_criterios);

        $seccion->addTextBreak(2);


        $seccion->addTitle('TERCERO. Resultado de la evaluación', 3);
        $seccion->addText('Los jurados consignaron los resultados de su evaluación en una planilla por cada una de las propuestas participantes y realizada la consolidación de las calificaciones se obtuvo la siguiente puntuación:');

        $seccion->addTextBreak(2);



        //consulta

        $phql = "SELECT
                  distinct (p.id), p.*
               FROM
                  Propuestas p
                  inner join Evaluacionpropuestas ep ON p.id = ep.propuesta
              WHERE
              ep.ronda = " . $ronda
                . " AND p.estado in (24,33)"
                . " AND ep.fase='Deliberación'"
                . " ORDER BY p.codigo";

        $propuestas = $this->modelsManager->createQuery($phql)->execute();

        // propuestas_evaluacion	Confirmada
        $fase = 'Deliberación';
        $estado_confirmada = Estados::findFirst(" tipo_estado = 'propuestas_evaluacion' AND nombre = 'Confirmada' ");

        $query = " SELECT
                                          distinct p.id, p.*,
                                          (	SELECT
                                              sum(total) AS total
                                            FROM
                                              Evaluacionpropuestas e
                                           WHERE
                                              e.propuesta = p.id AND e.estado  = " . $estado_confirmada->id
                . " AND fase = '" . $fase . "' AND ronda = '" . $ronda . "'"; //Se agrega la ronda
        $query .= " ) AS suma,
                                          (	SELECT
                                              count(e.id) AS cantidad
                                            FROM
                                              Evaluacionpropuestas e
                                            WHERE
                                                e.propuesta = p.id AND e.estado = " . $estado_confirmada->id
                . " AND fase = '" . $fase . "' AND ronda = '" . $ronda . "'"; //Se agrega la ronda
        $query .= " ) AS cantidad,
                                          (	SELECT
                                              avg(total) AS promedio
                                            FROM
                                              Evaluacionpropuestas e
                                            WHERE
                                            e.propuesta = p.id AND e.estado = " . $estado_confirmada->id
                . " AND fase = '" . $fase . "' AND ronda = '" . $ronda . "'"; //Se agrega la ronda
        $query .= " ) AS promedio,
                                    p.estado,
                                  'ganador' as rol
                                      FROM
                                        Propuestas AS p
                                        INNER JOIN
                                           Evaluacionpropuestas as ep2
                                        ON p.id = ep2.propuesta
                                      WHERE
                                        p.convocatoria  = " . $convocatoriaronda->convocatoria . " AND p.estado in (24,33) "; //. $estado_recomendada->id;
        $query .= "  AND ep2.estado = " . $estado_confirmada->id . " AND fase = '" . $fase . "' AND ep2.ronda = " . $ronda;
        $query .= "  ORDER BY promedio DESC ";

        $propuestas = $this->modelsManager->executeQuery($query);



        $fancyTableStyleName = 'Fancy Table';
        $cellRowSpan = array('vMerge' => 'restart', 'valign' => 'center', 'bgColor' => 'FFFF00');
        $fancyTableStyle = array('borderSize' => 1, 'borderColor' => '999999', 'alignment' => \PhpOffice\PhpWord\SimpleType\JcTable::CENTER); //cambiar
        $fancyTableFirstRowStyle = array('alignment' => \PhpOffice\PhpWord\SimpleType\JcTable::CENTER);
        $fancyTableCellStyle = array('valign' => 'center', 'bgColor' => 'dedbda',);
        $fancyTableCellBtlrStyle = array('valign' => 'center', 'textDirection' => \PhpOffice\PhpWord\Style\Cell::TEXT_DIR_BTLR);
        $fancyTableFontStyle = array('bold' => true);
        $documento->addTableStyle($fancyTableStyleName, $fancyTableStyle, $fancyTableFirstRowStyle, $cellRowSpan);
        $table = $seccion->addTable($fancyTableStyleName);
        $table->addRow(900);
        $table->addCell(500, $fancyTableCellStyle)->addText('No', $fancyTableFontStyle, $cellRowSpan, $fontStyle9);
        $table->addCell(1000, $fancyTableCellStyle)->addText('Código de la propuesta', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);
        $table->addCell(1000, $fancyTableCellStyle)->addText('Tipo de participante', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);
        $table->addCell(1250, $fancyTableCellStyle)->addText('Nombre del participante', $fancyTableFontStyle, $fancyTableFirstRowStyle);
        $table->addCell(1000, $fancyTableCellStyle)->addText('Tipo y número de documento de identidad', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);
        $table->addCell(1250, $fancyTableCellStyle)->addText('Nombre del representante', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);
        $table->addCell(3500, $fancyTableCellStyle)->addText('Nombre de la propuesta', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);
        $table->addCell(1000, $fancyTableCellStyle)->addText('Puntaje final', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);

        //por cada propuesta evaluada

        $prop = 0;

        foreach ($propuestas as $propuesta) {
            $prop++;

            $participante_actual = Participantes::findFirst('id = ' . $propuesta->p->participante);



            $usuarioperfil = Usuariosperfiles::findFirst(['id = ' . $participante_actual->usuario_perfil]);

            /*
             * 31-07-2020
             * WIlmer Gustavo Mogollón Duque
             * Se incorpora el tipo y npumero de documento para persona natural
             */

            $nombre_participante = $participante_actual->primer_nombre . " " . $participante_actual->segundo_nombre . " " . $participante_actual->primer_apellido . " " . $participante_actual->segundo_apellido;

            $perfil = Perfiles::findFirst([' id = ' . $usuarioperfil->perfil]);

            $td = "";

            if ($perfil->nombre == 'Agrupación' || $perfil->nombre == 'Persona Jurídica') {

                $participantepadre = Participantes::findFirst(
                                [
                                    'participante_padre = ' . $propuesta->p->participante
                                    . ' AND representante = true'
                                ]
                );


                $td = "";

                if (isset($participantepadre->id)) {
                    $td = Tiposdocumentos::findFirst('id = ' . $participantepadre->tipo_documento);
                    $representante = $participantepadre->primer_nombre . " " . $participantepadre->segundo_nombre . " " . $participantepadre->primer_apellido . " " . $participantepadre->segundo_apellido;
                    $td = $td->nombre . " " . $participantepadre->numero_documento;
                }
            } else {

                $td_pn = Tiposdocumentos::findFirst('id = ' . $participante_actual->tipo_documento)->nombre;
                $nombre_participante = $nombre_participante . " -- " . $td_pn . " " . $participante_actual->numero_documento;
                $representante = "";
            }
            
            


            $prom = number_format((float) $propuesta->promedio, 1, '.', '');

            $table->addRow();
            $table->addCell(500)->addText($prop);
            $table->addCell(1000)->addText(htmlspecialchars($propuesta->p->codigo), $fontStyle9);
            $table->addCell(1000)->addText(htmlspecialchars($perfil->nombre), $fontStyle9);
            $table->addCell(1250)->addText(htmlspecialchars($nombre_participante), $fontStyle9);
            $table->addCell(1000)->addText(htmlspecialchars($td), $fontStyle9);
            $table->addCell(1250)->addText(htmlspecialchars($representante), $fontStyle9);
            $table->addCell(3500)->addText(htmlspecialchars($propuesta->p->nombre), $fontStyle9);
            $table->addCell(1000)->addText($prom, $fontStyle9);
        }

        $seccion->addTextBreak(2);



        if ($convocatoria->estado == 43) {
            $seccion->addTitle('CUARTO. Recomendación de preseleccionados', 3);
            $seccion->addText('Analizados los resultados de la evaluación y realizada la deliberación de la convocatoria '
                    . $nombrec . ' el jurado recomienda declarar desierta la convocatoria.');
        } else {
            //Pregunto que tipo de acta voy a imprimir
            if ($convocatoriaronda->tipo_acta == 'Preselección') {
                $seccion->addTitle('CUARTO. Recomendación de preseleccionados', 3);
                $seccion->addText('Analizados los resultados de la evaluación y realizada la deliberación de la convocatoria '
                        . $nombrec . ' el jurado recomienda la siguiente preselección:');


                $seccion->addTextBreak(2);

                //Propuestas habilitadas
                //Estado propuestas	Habilitada
                $estado_recomendada = Estados::findFirst(" tipo_estado = 'propuestas' AND nombre = 'Recomendada como Ganadora' ");

                // propuestas_evaluacion	Confirmada
                $fase = 'Deliberación';
                $estado_confirmada = Estados::findFirst(" tipo_estado = 'propuestas_evaluacion' AND nombre = 'Confirmada' ");

                $query = " SELECT
                                          distinct p.id, p.*,
                                          (	SELECT
                                              sum(total) AS total
                                            FROM
                                              Evaluacionpropuestas e
                                           WHERE
                                              e.propuesta = p.id AND e.estado = " . $estado_confirmada->id
                        . " AND fase = '" . $fase . "' AND ronda = '" . $ronda . "'"; //Se agrega la ronda
                $query .= " ) AS suma,
                                          (	SELECT
                                              count(e.id) AS cantidad
                                            FROM
                                              Evaluacionpropuestas e
                                            WHERE
                                                e.propuesta = p.id AND e.estado = " . $estado_confirmada->id
                        . " AND fase = '" . $fase . "' AND ronda = '" . $ronda . "'"; //Se agrega la ronda
                $query .= " ) AS cantidad,
                                          (	SELECT
                                              avg(total) AS promedio
                                            FROM
                                              Evaluacionpropuestas e
                                            WHERE
                                            e.propuesta = p.id AND e.estado = " . $estado_confirmada->id
                        . " AND fase = '" . $fase . "' AND ronda = '" . $ronda . "'"; //Se agrega la ronda
                $query .= " ) AS promedio,
                                    p.estado,
                                  'ganador' as rol
                                      FROM
                                        Propuestas AS p
                                        INNER JOIN
                                           Evaluacionpropuestas as ep2
                                        ON p.id = ep2.propuesta
                                      WHERE
                                        p.convocatoria  = " . $convocatoriaronda->convocatoria . " AND p.estado = " . $estado_recomendada->id;
                $query .= "  AND ep2.estado = " . $estado_confirmada->id . " AND fase = '" . $fase . "' AND ep2.ronda = " . $ronda;
                $query .= "  ORDER BY promedio DESC ";

                $ganadores = $this->modelsManager->executeQuery($query);



                $fancyTableStyleName = 'Fancy Table';
                $cellRowSpan = array('vMerge' => 'restart', 'valign' => 'center', 'bgColor' => 'FFFF00');
                $fancyTableStyle = array('borderSize' => 1, 'borderColor' => '999999', 'alignment' => \PhpOffice\PhpWord\SimpleType\JcTable::CENTER); //cambiar
                $fancyTableFirstRowStyle = array('alignment' => \PhpOffice\PhpWord\SimpleType\JcTable::CENTER);
                $fancyTableCellStyle = array('valign' => 'center', 'bgColor' => 'dedbda',);
                $fancyTableCellBtlrStyle = array('valign' => 'center', 'textDirection' => \PhpOffice\PhpWord\Style\Cell::TEXT_DIR_BTLR);
                $fancyTableFontStyle = array('bold' => true);
                $documento->addTableStyle($fancyTableStyleName, $fancyTableStyle, $fancyTableFirstRowStyle, $cellRowSpan);
                $table = $seccion->addTable($fancyTableStyleName);
                $table->addRow(900);
                $table->addCell(1000, $fancyTableCellStyle)->addText('Código de la propuesta', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);
                $table->addCell(1000, $fancyTableCellStyle)->addText('Tipo de participante', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);
                $table->addCell(1500, $fancyTableCellStyle)->addText('Nombre del participante', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);
                $table->addCell(1000, $fancyTableCellStyle)->addText('Tipo y número de documento de identidad', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);
                $table->addCell(1500, $fancyTableCellStyle)->addText('Nombre del representante', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);
                $table->addCell(3500, $fancyTableCellStyle)->addText('Nombre de la propuesta', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);
                $table->addCell(1000, $fancyTableCellStyle)->addText('Puntaje final', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);

                //por cada propuesta evaluada

                foreach ($ganadores as $ganador) {


                    $participante_actual = Participantes::findFirst('id = ' . $ganador->p->participante);

                    $usuarioperfil = Usuariosperfiles::findFirst(['id = ' . $participante_actual->usuario_perfil]);

                    $perfil = Perfiles::findFirst([' id = ' . $usuarioperfil->perfil]);

                    /*
                     * 31-07-2020
                     * WIlmer Gustavo Mogollón Duque
                     * Se incorpora el tipo y npumero de documento para persona natural
                     */

                    $nombre_participante = $participante_actual->primer_nombre . " " . $participante_actual->segundo_nombre . " " . $participante_actual->primer_apellido . " " . $participante_actual->segundo_apellido;


                    if ($perfil->nombre == 'Agrupación' || $perfil->nombre == 'Persona Jurídica') {

                        $participantepadre = Participantes::findFirst(
                                        [
                                            'participante_padre = ' . $ganador->p->participante
                                            . ' AND representante = true'
                                        ]
                        );


                        $td = "";

                        if (isset($participantepadre->id)) {
                            $td = Tiposdocumentos::findFirst('id = ' . $participantepadre->tipo_documento);
                            $representante = $participantepadre->primer_nombre . " " . $participantepadre->segundo_nombre . " " . $participantepadre->primer_apellido . " " . $participantepadre->segundo_apellido;
                            $td = $td->nombre . " " . $participantepadre->numero_documento;
                        }
                    } else {
                        $td_pn = Tiposdocumentos::findFirst('id = ' . $participante_actual->tipo_documento)->nombre;
                        $nombre_participante = $nombre_participante . " -- " . $td_pn . " " . $participante_actual->numero_documento;
                        $representante = "";
                    }


                    $prom = number_format((float) $ganador->promedio, 1, '.', '');


                    $table->addRow();
                    $table->addCell(1000)->addText(htmlspecialchars($ganador->p->codigo), $fontStyle9);
                    $table->addCell(1000)->addText(htmlspecialchars($perfil->nombre), $fontStyle9);
                    $table->addCell(1250)->addText(htmlspecialchars($nombre_participante), $fontStyle9);
                    $table->addCell(1000)->addText(htmlspecialchars($td), $fontStyle9);
                    $table->addCell(1500)->addText(htmlspecialchars($representante), $fontStyle9);
                    $table->addCell(3000)->addText(htmlspecialchars($ganador->p->nombre), $fontStyle9);
                    $table->addCell(1000)->addText(htmlspecialchars($prom), $fontStyle9);
                }
            } else {
                if ($convocatoriaronda->tipo_acta == 'Ganadores') {
                    $seccion->addTitle('CUARTO. Recomendación de adjudicación', 3);
                    $seccion->addText('Analizados los resultados de la evaluación y realizada la deliberación de la convocatoria '
                            . $nombrec . ' el jurado recomienda el otorgamiento del estímulo a la(s) siguiente(s) propuesta(s):');


                    $seccion->addTextBreak(2);
                    //Propuestas habilitadas
                    //Estado propuestas	Habilitada
                    $estado_recomendada = Estados::findFirst(" tipo_estado = 'propuestas' AND nombre = 'Recomendada como Ganadora' ");

                    // propuestas_evaluacion	Confirmada
                    $fase = 'Deliberación';
                    $estado_confirmada = Estados::findFirst(" tipo_estado = 'propuestas_evaluacion' AND nombre = 'Confirmada' ");

                    $query = " SELECT
                                          distinct p.id, p.*,
                                          (	SELECT
                                              sum(total) AS total
                                            FROM
                                              Evaluacionpropuestas e
                                           WHERE
                                              e.propuesta = p.id AND e.estado = " . $estado_confirmada->id
                            . " AND fase = '" . $fase . "' AND ronda = '" . $ronda . "'"; //Se agrega la ronda
                    $query .= " ) AS suma,
                                          (	SELECT
                                              count(e.id) AS cantidad
                                            FROM
                                              Evaluacionpropuestas e
                                            WHERE
                                                e.propuesta = p.id AND e.estado = " . $estado_confirmada->id
                            . " AND fase = '" . $fase . "' AND ronda = '" . $ronda . "'"; //Se agrega la ronda
                    $query .= " ) AS cantidad,
                                          (	SELECT
                                              avg(total) AS promedio
                                            FROM
                                              Evaluacionpropuestas e
                                            WHERE
                                            e.propuesta = p.id AND e.estado = " . $estado_confirmada->id
                            . " AND fase = '" . $fase . "' AND ronda = '" . $ronda . "'"; //Se agrega la ronda
                    $query .= " ) AS promedio,
                                    p.estado,
                                  'ganador' as rol
                                      FROM
                                        Propuestas AS p
                                        INNER JOIN
                                           Evaluacionpropuestas as ep2
                                        ON p.id = ep2.propuesta
                                      WHERE
                                        p.convocatoria  = " . $convocatoriaronda->convocatoria . " AND p.estado = " . $estado_recomendada->id . " AND p.monto_asignado is not null "; // . " AND p.monto_asignado is not null "
                    $query .= "  AND ep2.estado = " . $estado_confirmada->id . " AND fase = '" . $fase . "' AND ep2.ronda = " . $ronda;
                    $query .= "  ORDER BY promedio DESC ";

                    $ganadores = $this->modelsManager->executeQuery($query);



                    $fancyTableStyleName = 'Fancy Table';
                    $cellRowSpan = array('vMerge' => 'restart', 'valign' => 'center', 'bgColor' => 'FFFF00');
                    $fancyTableStyle = array('borderSize' => 1, 'borderColor' => '999999', 'alignment' => \PhpOffice\PhpWord\SimpleType\JcTable::CENTER); //cambiar
                    $fancyTableFirstRowStyle = array('alignment' => \PhpOffice\PhpWord\SimpleType\JcTable::CENTER);
                    $fancyTableCellStyle = array('valign' => 'center', 'bgColor' => 'dedbda',);
                    $fancyTableCellBtlrStyle = array('valign' => 'center', 'textDirection' => \PhpOffice\PhpWord\Style\Cell::TEXT_DIR_BTLR);
                    $fancyTableFontStyle = array('bold' => true);
                    $documento->addTableStyle($fancyTableStyleName, $fancyTableStyle, $fancyTableFirstRowStyle, $cellRowSpan);
                    $table = $seccion->addTable($fancyTableStyleName);
                    $table->addRow(900);
                    $table->addCell(1000, $fancyTableCellStyle)->addText('Código de la propuesta', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);
                    $table->addCell(1000, $fancyTableCellStyle)->addText('Tipo de participante', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);
                    $table->addCell(1250, $fancyTableCellStyle)->addText('Nombre del participante', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);
                    $table->addCell(1000, $fancyTableCellStyle)->addText('Tipo y número de documento de identidad', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);
                    $table->addCell(1250, $fancyTableCellStyle)->addText('Nombre del representante', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);
                    $table->addCell(3000, $fancyTableCellStyle)->addText('Nombre de la propuesta', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);
                    $table->addCell(1000, $fancyTableCellStyle)->addText('Puntaje final', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);
                    $table->addCell(1000, $fancyTableCellStyle)->addText('Valor del estímulo', $fancyTableFontStyle, $fancyTableFirstRowStyle, $fontStyle9);

                    //por cada propuesta evaluada

                    foreach ($ganadores as $ganador) {

                        $participante_actual = Participantes::findFirst('id = ' . $ganador->p->participante);

                        $usuarioperfil = Usuariosperfiles::findFirst(['id = ' . $participante_actual->usuario_perfil]);

                        $perfil = Perfiles::findFirst([' id = ' . $usuarioperfil->perfil]);
                        
                        /*
                     * 31-07-2020
                     * WIlmer Gustavo Mogollón Duque
                     * Se incorpora el tipo y npumero de documento para persona natural
                     */

                    $nombre_participante = $participante_actual->primer_nombre . " " . $participante_actual->segundo_nombre . " " . $participante_actual->primer_apellido . " " . $participante_actual->segundo_apellido;


                        if ($perfil->nombre == 'Agrupación' || $perfil->nombre == 'Persona Jurídica') {

                            $participantepadre = Participantes::findFirst(
                                            [
                                                'participante_padre = ' . $ganador->p->participante
                                                . ' AND representante = true'
                                            ]
                            );

                            $td = "";

                            if (isset($participantepadre->id)) {
                                $td = Tiposdocumentos::findFirst('id = ' . $participantepadre->tipo_documento);
                                $representante = $participantepadre->primer_nombre . " " . $participantepadre->segundo_nombre . " " . $participantepadre->primer_apellido . " " . $participantepadre->segundo_apellido;
                                $td = $td->nombre . " " . $participantepadre->numero_documento;
                            }
                        } else {
                            $td_pn = Tiposdocumentos::findFirst('id = ' . $participante_actual->tipo_documento)->nombre;
                            $nombre_participante = $nombre_participante . " -- " . $td_pn . " " . $participante_actual->numero_documento;
                            $representante = "";
                        }


                        $prom = number_format((float) $ganador->promedio, 1, '.', '');


                        $table->addRow();
                        $table->addCell(1000)->addText(htmlspecialchars($ganador->p->codigo), $fontStyle9);
                        $table->addCell(1000)->addText(htmlspecialchars($perfil->nombre), $fontStyle9);
                        $table->addCell(1250)->addText(htmlspecialchars($nombre_participante), $fontStyle9);
                        $table->addCell(1000)->addText(htmlspecialchars($td), $fontStyle9);
                        $table->addCell(1500)->addText(htmlspecialchars($representante), $fontStyle9);
                        $table->addCell(3000)->addText(htmlspecialchars($ganador->p->nombre), $fontStyle9);
                        $table->addCell(1000)->addText(htmlspecialchars($prom), $fontStyle9);
                        $table->addCell(1000)->addText(htmlspecialchars($ganador->p->monto_asignado), $fontStyle9);
                    }
                }
            }
        }






        if ($convocatoria->estado != 43) {
            
        }

        $seccion->addTextBreak(2);


        $seccion->addTitle('Quinto. Observaciones del jurado', 3);
        $seccion->addText('Aspectos destacados de las propuestas ganadoras:');
        $seccion->addText($convocatoriaronda->aspectos);
        $seccion->addTextBreak(2);
        $seccion->addText('Recomendaciones para la ejecución de las propuestas ganadoras:');
        $seccion->addText($convocatoriaronda->recomendaciones);
        $seccion->addTextBreak(2);
        $seccion->addText('Comentarios generales sobre la convocatoria y las propuestas participantes:');
        $seccion->addText($convocatoriaronda->comentarios);
        $seccion->addTextBreak(2);





        $seccion->addTextBreak(2);

        $seccion->addText('Para constancia firman,');

        $seccion->addTextBreak(2);




        foreach ($evaluadores as $evaluador) {

            $juradopostulado = Juradospostulados::findFirst('id = ' . $evaluador->juradopostulado);
            $tipodocumento = Tiposdocumentos::findFirst('id = ' . $juradopostulado->Propuestas->Participantes->tipo_documento);



            $seccion->addText('______________________________________');
            $seccion->addText($juradopostulado->Propuestas->Participantes->primer_nombre . " "
                    . $juradopostulado->Propuestas->Participantes->segundo_nombre . " "
                    . $juradopostulado->Propuestas->Participantes->primer_apellido . " "
                    . $juradopostulado->Propuestas->Participantes->segundo_apellido);
            $seccion->addText($tipodocumento->nombre . " " . $juradopostulado->Propuestas->Participantes->numero_documento);
            $seccion->addTextBreak(4);
        }





# Para que no diga que se abre en modo de compatibilidad
        $documento->getCompatibility()->setOoxmlVersion(15);
# Idioma español de México
        $documento->getSettings()->setThemeFontLang(new Language("ES-MX"));
# Enviar encabezados para indicar que vamos a enviar un documento de Word
        //Pregunto que tipo de acta voy a imprimir
        if ($convocatoriaronda->tipo_acta == 'Preselección') {
            $nombre = "Acta de preseleccionados " . $convocatoriaronda->id . ".docx";
        } else {
            if ($convocatoriaronda->tipo_acta == 'Ganadores') {
                $nombre = "Acta de ganadores " . $convocatoriaronda->id . ".docx";
            }
        }



//        $nombre = "Acta de preseleccionados.docx";
        header("Content-Description: File Transfer");
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Expires: 0');


        $objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($documento, "Word2007");
# Y lo enviamos a php://output
        $objWriter->save("php://output");
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo reporte_listado_entidades_convocatorias_estado_xls al generar el reporte listado de la propuesta (' . $request->getPut('id') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

$app->get('/evaluacionpropuestas/ronda/{ronda:[0-9]+}', function ($ronda) use ($app, $config) {
    try {


        //ordenar
        $phql = 'SELECT
                  distinct (p.id), p.*
               FROM
                  Propuestas p
                  inner join Evaluacionpropuestas ep ON p.id = ep.propuesta
              WHERE
              ep.ronda = ' . $ronda;

        $rs = $this->modelsManager->createQuery($phql)->execute();

        //  echo json_encode($rs->count());

        foreach ($rs as $row) {
            //echo json_encode($rs);

            $evaluacionpropuestas = Evaluacionpropuestas::find(
                            [
                                ' propuesta = ' . $row->p->id
                                . ' AND ronda = ' . $ronda
                            ]
            );



            $participantes = Participantes::findFirst('id = ' . $row->p->participante);

            echo "<b>Código propuesta: " . $row->p->codigo . "<br>";
            echo "<b>Nombre Participante: " . $participantes->primer_nombre . " "
            . $participantes->segundo_nombre . " "
            . $participantes->primer_apellido . " "
            . $participantes->primer_apellido . "<br>";
            echo "<b>Nombre Propuesta: " . $row->p->nombre . "<br>";


            foreach ($evaluacionpropuestas as $evaluacionpropuesta) {

                $evaluador = Evaluadores::findFirst('id = ' . $evaluacionpropuesta->evaluador);
                $juradopostulado = Juradospostulados::findFirst('id = ' . $evaluador->juradopostulado);
                echo "</br><b><strong>Información de la evaluación:</strong></br>";
                echo "<b>Código del jurado :" . $juradopostulado->Propuestas->codigo;
                echo "<br>Nombre del jurado:" . $juradopostulado->Propuestas->Participantes->primer_nombre . " "
                . $juradopostulado->Propuestas->Participantes->segundo_nombre . " "
                . $juradopostulado->Propuestas->Participantes->primer_apellido . " "
                . $juradopostulado->Propuestas->Participantes->segundo_apellido . "</br>";
                echo "Total evaluación: " . $evaluacionpropuesta->total . "</br></br></br>";

                //criterios de la ronda

                $criterios = Convocatoriasrondascriterios::find(
                                [
                                    'convocatoria_ronda = ' . $ronda
                                    . ' AND active= true',
                                    'order' => 'orden ASC'
                                ]
                );

                echo "<table style='border: 1px solid black;'>
                        <tr >
                          <td style='border: 1px solid black;background-color:#efe8e6; text-align: center;'>Número</td>
                          <td style='border: 1px solid black;background-color:#efe8e6; text-align: center;'>Criterio</td>
                          <td style='border: 1px solid black;background-color:#efe8e6; text-align: center;'>Puntaje máximo</td>
                          <td style='border: 1px solid black;background-color:#efe8e6; text-align: center;'>Calificación</td>
                          <td style='border: 1px solid black;background-color:#efe8e6; text-align: center;'>Observación</td>
                        </tr>";


                $cont = 0;
                foreach ($criterios as $criterio) {
                    $cont++;

                    $evaluacioncriterio = Evaluacioncriterios::findFirst(
                                    [
                                        'evaluacionpropuesta = ' . $evaluacionpropuesta->id
                                        . ' AND criterio = ' . $criterio->id
                                        . ' AND active= true'
                                    ]
                    );

                    echo "<tr>
                            <td style='border: 1px solid black; text-align: center;'>" . $cont . "</td>
                            <td style='border: 1px solid black;'>" . $criterio->descripcion_criterio . "</td>
                            <td style='border: 1px solid black; text-align: center;'>" . $criterio->puntaje_maximo . "</td>
                            <td style='border: 1px solid black; text-align: center;'>" . $evaluacioncriterio->puntaje . "</td>
                            <td style='border: 1px solid black;'>" . $evaluacioncriterio->observacion . "</td>
                          </tr>";
                }

                echo "</table>";

//                $evaluador = Evaluadores::findFirst('id = ' . $evaluacionpropuesta->evaluador);
//                $juradopostulado = Juradospostulados::findFirst('id = ' . $evaluador->juradopostulado);
//
//                echo "</br></br>";
//                echo "<b>Código del jurado :" . $juradopostulado->Propuestas->codigo;
//                echo "<br>Nombre del jurado:" . $juradopostulado->Propuestas->Participantes->primer_nombre . " " . $juradopostulado->Propuestas->Participantes->segundo_nombre . " " . $juradopostulado->Propuestas->Participantes->primer_apellido . " " . $juradopostulado->Propuestas->Participantes->segundo_apellido;
                echo "</b></br></br>";
                echo "<hr>";
            }
        }
    } catch (Exception $ex) {
        //return "error_metodo";
        //Para auditoria en versión de pruebas
        return "error_metodo" . $ex->getMessage() . json_encode($ex->getTrace());
    }
}
);



/*
 * 22-07-2020
 * Wilmer Gustavo Mogollón Duque
 * Se incorpora método para generar acta de aceptación de notificación
 */

$app->get('/carta_aceptacion_notificacion/postulacion/{postulacion:[0-9]+}', function ($postulacion) use ($app, $config, $logger) {
    try {


        /*
         * Creo el objeto para traer los datos de la notificación
         */

        $notificacion = Juradosnotificaciones::findFirst(
                        [
                            'juradospostulado = ' . $postulacion
                            . ' AND active= true'
                        ]
        );

        $juradopostulado = Juradospostulados::findFirst(
                        [
                            'id = ' . $postulacion
                        ]
        );


        $convocatoria = Convocatorias::findFirst(
                        [
                            'id = ' . $juradopostulado->convocatoria
                            . ' AND active= true'
                        ]
        );

        $programa = Programas::findFirst(['id=' . $convocatoria->programa]);


        if ($convocatoria->convocatoria_padre_categoria == null) {
            $nombrec = $convocatoria->nombre;
        } else {
            $convocatoriapadre = Convocatorias::findFirst(
                            [
                                'id = ' . $convocatoria->convocatoria_padre_categoria
                                . ' AND active= true'
                            ]
            );
            $nombrec = $convocatoriapadre->nombre . " - " . $convocatoria->nombre;
        }

        $entidad = Entidades::findFirst(
                        [
                            'id = ' . $convocatoria->entidad
                            . ' AND active= true'
                        ]
        );

        $propuestashabilitadas = Propuestas::find(
                        [
                            'convocatoria = ' . $convocatoria->id
                            . ' AND active= true'
                            . ' AND estado in (24,33)'
                        ]
        );


        $participante = $juradopostulado->Propuestas->Participantes->primer_nombre . " "
                . $juradopostulado->Propuestas->Participantes->segundo_nombre . " "
                . $juradopostulado->Propuestas->Participantes->primer_apellido . " "
                . $juradopostulado->Propuestas->Participantes->segundo_apellido;


        echo "<div style='text-align: center;'>";
        echo "" . $entidad->nombre . "<br>";
        echo "" . $programa->nombre . "<br>";
        echo "" . $nombrec . "<br>";
        echo "<br>";
        echo "<br>";
        echo "A quien pueda interesar";
        echo "<br>";
        echo "<br>";
        echo "</div>";



        if ($notificacion == false) {
            echo "La postulación de " . $participante . " no ha sido notificada para ser jurado.";
        } else {
            $estado_notificacion = Estados::findFirst(" tipo_estado = 'jurado_notificaciones' AND id = " . $notificacion->estado)->nombre;

//            return $estado_notificacion;

            switch ($estado_notificacion) {
                case "Notificada":

                    echo $participante . " aún no ha dado respuesta a ésta notificación";

                    break;

                case "Declinada":

                    echo "El estado actual de la postulación de  " . $participante . " es declinada";

                    break;

                case "Aceptada":

                    echo "Yo " . $participante . ", identificado(a) con el documento de identidad " . $juradopostulado->Propuestas->Participantes->numero_documento . " manifiesto que:<br/><br/>";
                    echo " SI acepto la designación como jurado " . $juradopostulado->rol . " para la convocatoria denominada "
                    . $nombrec
                    . ' de '
                    . $entidad->nombre . ".<br/><br/>";

                    echo "Dicha convocatoria tiene un total de  " . $propuestashabilitadas->count() . " propuestas habilitadas para ser evaluadas por parte del jurado. "
                    . ' con un estímulo asignado por concepto de evaluación por la suma de: $'
                    . $notificacion->valor_estimulo . " pesos modeda corriente.<br/><br/>";


                    echo "Comprendo las siguientes facultades de los jurados del Programa Distrital de Estímulos, en adelante PDE, para la actual vigencia 
                            <ul>
                                <li>Efectuar la recomendación de selección teniendo en cuenta que la propuesta o propuestas ganadoras deben ser las
                                    que hayan obtenido el puntaje o puntajes más altos una vez realizada la deliberación, en todo caso respetando siempre
                                    el puntaje mínimo establecido para ser ganador en cada convocatoria del PDE. Su recomendación de selección será inapelable.</li>
                                <li>Recomendar que la convocatoria se declare desierta en su totalidad o en alguna de sus categorías o ciclos, si durante la
                                    deliberación encuentra por unanimidad que las propuestas evaluadas no ameritan el otorgamiento del estímulo. En este caso,
                                    el jurado expondrá las razones que tuvo en cuenta para tomar esta decisión.</li>
                                <li>Definir suplentes de los ganadores para los casos de inhabilidad, impedimento o renuncia.</li>
                                <li>Recomendar el otorgamiento de menciones a aquellas propuestas que considere. Esta decisión deberá quedar consignada
                                    en el acta de recomendación de ganadores.</li>
                                <li>Realizar recomendaciones a las propuestas ganadoras para que sean tenidas en cuenta durante la ejecución, siempre y cuando
                                    éstas no modifiquen el propósito y alcance.</li>
                            </ul>
                            <br/>";

                    echo "Acepto los compromisos que se relacionan a continuación y que hacen parte de las condiciones generales de participación del PDE.";


                    echo "<ul>
                                <li>Leer detenidamente las condiciones generales de participación del PDE y los requisitos específicos de la
                                    convocatoria de la cual es jurado.</li>
                                <li>Una vez recibido el acceso al material para la evaluación, verificar que se encuentra la totalidad de las propuestas asignadas
                                    e informar cualquier inconsistencia a la entidad responsable de la convocatoria.</li>
                                <li>Declararse impedido, mediante comunicación escrita, con mínimo cinco (5) días hábiles antes de la fecha de deliberación, respecto
                                    de las propuestas frente a los cuales identifique la existencia de inhabilidad o conflicto de intereses, o frente aquellas en las
                                    que considere que no puede emitir un concepto objetivo.</li>
                                <li>Asistir a las reuniones, audiciones, visitas de campo y demás actividades programadas por la entidad responsable de la convocatoria,
                                    durante el proceso de evaluación, en el lugar, fecha y hora que le sean indicados.</li>
                                <li>Leer y evaluar, previo a la deliberación, las propuestas de la convocatoria para la cual fue seleccionado como jurado.</li>
                                <li>Presentar por escrito a la entidad encargada, con la debida anterioridad, las consultas y solicitudes
                                    de aclaración sobre la convocatoria que debe evaluar (en ningún caso la entidad resolverá inquietudes formuladas verbalmente).</li>
                                <li>Tener en cuenta los criterios de evaluación establecidos para cada convocatoria y realizar la selección de
                                    conformidad con los principios de objetividad, transparencia y autonomía.</li>
                                <li>Diligenciar una planilla de evaluación por cada obra o propuesta recibida, emitiendo un concepto técnico
                                    por cada criterio de valoración o una recomendación que retroalimente al participante.</li>
                                <li>Elaborar, sustentar y firmar el acta de recomendación de ganadores de la convocatoria que evaluó.</li>
                                <li>Acudir ante la entidad y presentar por escrito las aclaraciones que le sean requeridas, en el evento
                                    de presentarse solicitudes efectuadas por terceros, organismos de control o participantes.</li>
                                <li>Cumplir éticamente los deberes encomendados como jurado, procurando siempre la observancia de los
                                    principios de igualdad, buena fe y dignidad humana consignados en la Constitución</li>
                                <li>Realizar un informe final por cada una de las convocatorias en las que participó como evaluador,
                                    este documento deberá incluir recomendaciones para el fortalecimiento del PDE.</li>
                                <li>Mantener absoluta confidencialidad en el manejo de la información durante todo el proceso evaluación.</li>
                                <li>Abstenerse de hacer uso de la información a que accede en su condición de jurado, para cualquier objetivo
                                    diferente de la evaluación, respetando siempre los derechos de autor del participante.</li>
                            </ul>";

                    echo "Se entregará el 100% del valor determinado como reconocimiento económico, una vez el jurado haya cumplido con todas y cada una de sus
                                    obligaciones, previa entrega de la siguiente documentación y de la certificación expedida por la entidad encargada de la convocatoria:
                                    Fotocopia del Certificado de Registro Único Tributario - RUT (para nacionales). Certificación bancaria a nombre del jurado, no mayor a
                                    treinta (30) días de expedida, en la que conste que la cuenta está activa, cuál es su tipo (ahorros o corriente) y número. En caso de no
                                    tener cuenta, deberá consultar a la entidad los mecanismos establecidos para otras formas de pago. Certificación de afiliación activa a
                                    salud correspondiente al mes en el que se tramita el pago. En el caso de jurados extranjeros no residentes en Colombia, solo se solicitará
                                    la certificación bancaria a nombre el jurado, no mayor a treinta (30) días de expedida. Los jurados extranjeros residentes en Colombia
                                    deberán presentar los mismos documentos que los jurados nacionales.<br/><br/>";

                    echo "Nota: En caso de que un jurado incumpla con alguno de los compromisos estipulados en el presente documento, la entidad otorgante lo
                                    requerirá para que dé las explicaciones pertinentes. De no atender dicho requerimiento dentro de los cinco (5) días hábiles siguientes o
                                    no cumplir los compromisos acordados, la entidad procederá a retirar el estímulo de manera unilateral mediante acto administrativo, declarando
                                    su incumplimiento y se establecerá que el ganador no podrá participar por el término de los dos (2) años siguientes en las convocatorias del PDE.<br/><br/>";


                    echo "Fecha de aceptación: " . $notificacion->fecha_aceptacion;

                    break;

                case "Rechazada":

                    echo "Yo " . $participante . ", identificado(a) con el documento de identidad " . $juradopostulado->Propuestas->Participantes->numero_documento . " manifiesto que:<br/><br/>";
                    echo " NO acepto la designación como jurado " . $juradopostulado->rol . " para la convocatoria denominada "
                    . $nombrec
                    . ' de '
                    . $entidad->nombre . ".<br/><br/>";


                    echo "Fecha de rechazo: " . $notificacion->fecha_rechazo;

                    break;

                default:
                    break;
            }
        }
    } catch (Exception $ex) {
        //return "error_metodo";
        //Para auditoria en versión de pruebas
        return "error_metodo" . $ex->getMessage() . json_encode($ex->getTrace());
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