<?php

//error_reporting(E_ALL);
//ini_set('display_errors', '1');
use Phalcon\Loader;
use Phalcon\Mvc\Micro;
use Phalcon\Di\FactoryDefault;
use Phalcon\Db\Adapter\Pdo\Postgresql as DbAdapter;
use Phalcon\Config\Adapter\Ini as ConfigIni;
use Phalcon\Http\Request;
use Phalcon\Logger\Adapter\File as FileAdapter;
use Phalcon\Logger\Formatter\Line;

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
        "host" => $config->database->host,"port" => $config->database->port,
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

//Metodo que consulta el participante, con el cual va a registar la propuesta
//Se realiza la busqueda del participante
//Si no existe en inicial lo enviamos a crear el perfil
//Si existe el participante asociado a la propuesta se retorna
$app->get('/buscar_propuesta', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestasPdac en el método buscar_propuesta, consultar la propuesta para cargar formularios (' . $request->get('p') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
            
            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
                //Busco si tiene el perfil asociado de acuerdo al parametro
                if ($request->get('m') == "pn") {
                    $tipo_participante = "Persona Natural";
                    $usuario_perfil = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil = 6");
                }
                if ($request->get('m') == "pj") {
                    $tipo_participante = "Persona Jurídica";
                    $usuario_perfil = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil = 7");
                }
                if ($request->get('m') == "agr") {
                    $tipo_participante = "Agrupaciones";
                    $usuario_perfil = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil = 8");
                }

                if (isset($usuario_perfil->id)) {

                    //Consulto el participante inicial
                    $participante = Participantes::findFirst("usuario_perfil=" . $usuario_perfil->id . " AND tipo='Inicial' AND active=TRUE");

                    //Si existe el participante inicial con el perfil de acuerdo al parametro
                    if (isset($participante->id)) {

                        //Consulto la convocatoria
                        $convocatoria = Convocatorias::findFirst($request->get('conv'));

                        //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
                        $nombre_convocatoria = $convocatoria->nombre;
                        $nombre_categoria = "";
                        $modalidad = $convocatoria->modalidad;
                        if ($convocatoria->convocatoria_padre_categoria > 0) {
                            $nombre_convocatoria = $convocatoria->getConvocatorias()->nombre;
                            $nombre_categoria = $convocatoria->nombre;
                            $modalidad = $convocatoria->getConvocatorias()->modalidad;
                        }

                        //Valido si existe el codigo de la propuesta
                        //De lo contratio creo el participante del cual depende del inicial
                        //Creo la propuesta asociando el participante creado
                        if (is_numeric($request->get('p')) AND $request->get('p') != 0) {
                            //Consulto la propuesta solicitada
                            $conditions = ['id' => $request->get('p'), 'active' => true];
                            $propuesta = Propuestas::findFirst(([
                                        'conditions' => 'id=:id: AND active=:active:',
                                        'bind' => $conditions,
                            ]));

                            if (isset($propuesta->id)) {

                                $logger->info('"token":"{token}","user":"{user}","message":"Consulta en el controlador PropuestasPdac en el método buscar_propuesta, la propuesta (' . $request->get('p') . ') cuenta con perfil y participante inicial para la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                                                
                                //Consulto los parametros adicionales para el formulario de la propuesta
                                $conditions = ['convocatoria' => $convocatoria->id, 'active' => true];
                                $parametros = Convocatoriaspropuestasparametros::find(([
                                    'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                                    'bind' => $conditions,
                                    'order' => 'orden ASC',
                                ]));
                                $propuestaparametros = Propuestasparametros::find("propuesta=" . $propuesta->id);


                                //Creo el array de la propuesta
                                $array = array();
                                //Valido si se habilita propuesta por derecho de petición
                                $array["estado"] = $propuesta->estado;                                    
                                if($propuesta->habilitar)
                                {
                                    $fecha_actual = strtotime(date("Y-m-d H:i:s"), time());
                                    $habilitar_fecha_inicio = strtotime($propuesta->habilitar_fecha_inicio, time());
                                    $habilitar_fecha_fin = strtotime($propuesta->habilitar_fecha_fin, time());
                                    if (($fecha_actual >= $habilitar_fecha_inicio) && ($fecha_actual <= $habilitar_fecha_fin))
                                    {
                                        $array["estado"] = 7;                                    
                                    }
                                }                                 
                                $array["propuesta"]["nombre_participante"] = $propuesta->getParticipantes()->primer_nombre . " " . $propuesta->getParticipantes()->segundo_nombre . " " . $propuesta->getParticipantes()->primer_apellido . " " . $propuesta->getParticipantes()->segundo_apellido;
                                $array["propuesta"]["tipo_participante"] = $tipo_participante;
                                $array["propuesta"]["nombre_convocatoria"] = $nombre_convocatoria;
                                $array["propuesta"]["nombre_categoria"] = $nombre_categoria;
                                $array["propuesta"]["modalidad"] = $modalidad;
                                $array["propuesta"]["estado"] = $propuesta->getEstados()->nombre;
                                $array["propuesta"]["nombre"] = $propuesta->nombre;
                                $array["propuesta"]["resumen"] = $propuesta->resumen;
                                $array["propuesta"]["objetivo"] = $propuesta->objetivo;
                                $array["propuesta"]["bogota"] = $propuesta->bogota;
                                $array["propuesta"]["localidad"] = $propuesta->localidad;
                                $array["propuesta"]["upz"] = $propuesta->upz;
                                $array["propuesta"]["barrio"] = $propuesta->barrio;
                                $array["propuesta"]["ejecucion_menores_edad"] = $propuesta->ejecucion_menores_edad;
                                $array["propuesta"]["porque_medio"] = $propuesta->porque_medio;
                                
                                $array["propuesta"]["alianza_sectorial"] = $propuesta->alianza_sectorial;
                                $array["propuesta"]["primera_vez_pdac"] = $propuesta->primera_vez_pdac;
                                $array["propuesta"]["relacion_plan"] = $propuesta->relacion_plan;
                                $array["propuesta"]["linea_estrategica"] = $propuesta->linea_estrategica;
                                $array["propuesta"]["area"] = $propuesta->area;
                                $array["propuesta"]["trayectoria_entidad"] = $propuesta->trayectoria_entidad;
                                $array["propuesta"]["problema_necesidad"] = $propuesta->problema_necesidad;
                                $array["propuesta"]["diagnostico_problema"] = $propuesta->diagnostico_problema;
                                $array["propuesta"]["justificacion"] = $propuesta->justificacion;
                                $array["propuesta"]["atencedente"] = $propuesta->atencedente;
                                $array["propuesta"]["alcance_territorial"] = $propuesta->alcance_territorial;                                
                                $array["propuesta"]["objetivo_general"] = $propuesta->objetivo_general;                                
                                $array["propuesta"]["metodologia"] = $propuesta->metodologia;
                                $array["propuesta"]["impacto"] = $propuesta->impacto;
                                $array["propuesta"]["mecanismos_cualitativa"] = $propuesta->mecanismos_cualitativa;
                                $array["propuesta"]["mecanismos_cuantitativa"] = $propuesta->mecanismos_cuantitativa;
                                $array["propuesta"]["proyeccion_reconocimiento"] = $propuesta->proyeccion_reconocimiento;
                                $array["propuesta"]["impacto_proyecto"] = $propuesta->impacto_proyecto;
                                $array["propuesta"]["localidades"] = $propuesta->localidades;
                                $array["propuesta"]["poblacion_objetivo"] = $propuesta->poblacion_objetivo;
                                $array["propuesta"]["comunidad_objetivo"] = $propuesta->comunidad_objetivo;
                                $array["propuesta"]["establecio_cifra"] = $propuesta->establecio_cifra;
                                $array["propuesta"]["total_beneficiario"] = $propuesta->total_beneficiario;
                                                                                                
                                $array["propuesta"]["id"] = $propuesta->id;
                                
                                //parametros de territorio
                                $array_parametros_territorios = Propuestasterritorios::find("propuesta=".$propuesta->id);
                                foreach($array_parametros_territorios AS $clave => $valor){
                                    $array["propuesta_territorio"][$valor->variable]=$valor->valor;
                                }
                                
                                //consulto los objetivos especificos
                                $array["objetivos_especificos"]=$propuesta->getPropuestasobjetivos("active=true");
                                
                                //Recorro los valores de los parametros con el fin de ingresarlos al formulario
                                foreach ($propuestaparametros as $pp) {
                                    $array["propuesta"]["parametro[" . $pp->convocatoriapropuestaparametro . "]"] = $pp->valor;
                                }
                                $array["localidades"] = Localidades::find("active=true");
                                $array["parametros"] = $parametros;
                                
                                //Medio que se entero
                                $tabla_maestra = Tablasmaestras::find("active=true AND nombre='medio_se_entero'");
                                $array["medio_se_entero"] = explode(",", $tabla_maestra[0]->valor);
                                
                                //relacion_plan
                                $tabla_maestra = Tablasmaestras::find("active=true AND nombre='relacion_plan'");
                                $array["relacion_plan"] = explode(",", $tabla_maestra[0]->valor);
                                
                                //alcance_territorial
                                $tabla_maestra = Tablasmaestras::find("active=true AND nombre='alcance_territorial'");
                                $array["alcance_territorial"] = explode(",", $tabla_maestra[0]->valor);
                                
                                //Lineas estrategicas
                                $conditions = ['active' => true];
                                $array["linea_estrategica"] = Lineasestrategicas::find(([
                                    'conditions' => 'active=:active:',
                                    'bind' => $conditions,
                                    'order' => 'nombre ASC',
                                ]));
                                
                                //areas
                                $conditions = ['active' => true];
                                $array["area"] = Areas::find(([
                                    'conditions' => 'active=:active:',
                                    'bind' => $conditions,
                                    'order' => 'nombre ASC',
                                ]));
                                

                                //Creo los parametros obligatorios del formulario
                                //Validacion para metropolitana   
                                $array["convocatoria_padre_categoria"] = $convocatoria->convocatoria_padre_categoria;
                                if($convocatoria->convocatoria_padre_categoria==621)
                                {
                                    $options = array(
                                        "fields" => array(
                                            "localidad" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "La localidad principal en donde el proyecto desarrollará las acciones es requerido.")
                                                )
                                            ),
                                            "nombre" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "El nombre de la propuesta es requerido.")
                                                )
                                            ),
                                            "alianza_sectorial" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "El ¿Proyecto de alianza sectorial? es requerido.")
                                                )
                                            ),
                                            "primera_vez_pdac" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "El ¿Es la primera vez que la propuesta se presenta al PDAC? es requerido.")
                                                )
                                            ),
                                            "relacion_plan[]" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "La relación del proyecto con el Plan de Desarrollo de Bogotá es requerido.")                                                

                                                )
                                            ),
                                            "linea_estrategica[]" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "La línea estratégica del proyecto es requerido.")
                                                )
                                            ),
                                            "area[]" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "El área del proyecto es requerido.")
                                                )
                                            ),
                                            "trayectoria_entidad" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "La trayectoria de la entidad participante es requerido."),
                                                    "stringLength" => array("max" => 2000,"message" => "Ya cuenta con el máximo de caracteres permitidos, los cuales son 2000.")
                                                )
                                            ),
                                            "problema_necesidad" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "El problema o necesidad es requerido."),
                                                    "stringLength" => array("max" => 2000,"message" => "Ya cuenta con el máximo de caracteres permitidos, los cuales son 2000.")
                                                )
                                            ),
                                            "diagnostico_problema" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "El ¿Cómo se diagnosticó el problema o necesidad? es requerido."),
                                                    "stringLength" => array("max" => 2000,"message" => "Ya cuenta con el máximo de caracteres permitidos, los cuales son 2000.")
                                                )
                                            ),
                                            "justificacion" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "La justificacion es requerido."),
                                                    "stringLength" => array("max" => 2000,"message" => "Ya cuenta con el máximo de caracteres permitidos, los cuales son 2000.")
                                                )
                                            ),
                                            "atencedente" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "Los antecedentes generales del proyecto es requerido."),
                                                    "stringLength" => array("max" => 2000,"message" => "Ya cuenta con el máximo de caracteres permitidos, los cuales son 2000.")
                                                )
                                            ),
                                            "objetivo_general" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "El objetivo general es requerido."),
                                                    "stringLength" => array("max" => 1000,"message" => "Ya cuenta con el máximo de caracteres permitidos, los cuales son 1000.")
                                                )
                                            ),
                                            "objetivo_especifico" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "El objetivo específico es requerido."),
                                                    "stringLength" => array("max" => 500,"message" => "Ya cuenta con el máximo de caracteres permitidos, los cuales son 500.")
                                                )
                                            ),
                                            "meta" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "La meta es requerido."),
                                                    "stringLength" => array("max" => 500,"message" => "Ya cuenta con el máximo de caracteres permitidos, los cuales son 500.")
                                                )
                                            ),
                                            "actividad" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "La actividades es requerido.")
                                                )
                                            ),
                                            "metodologia" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "La metodología es requerido."),
                                                    "stringLength" => array("max" => 2000,"message" => "Ya cuenta con el máximo de caracteres permitidos, los cuales son 2000.")
                                                )
                                            ),
                                            "impacto" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "El impacto esperado es requerido."),
                                                    "stringLength" => array("max" => 2000,"message" => "Ya cuenta con el máximo de caracteres permitidos, los cuales son 2000.")
                                                )
                                            ),
                                            "mecanismos_cualitativa" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "El mecanismos de evaluación cualitativa: objetivos e impactos es requerido."),
                                                    "stringLength" => array("max" => 2000,"message" => "Ya cuenta con el máximo de caracteres permitidos, los cuales son 2000.")
                                                )
                                            ),
                                            "mecanismos_cuantitativa" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "El mecanismos de evaluación cuantitativa: cobertura poblacional y territorial es requerido."),
                                                    "stringLength" => array("max" => 2000,"message" => "Ya cuenta con el máximo de caracteres permitidos, los cuales son 2000.")
                                                )
                                            ),
                                            "alcance_territorial[]" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "El alcance territorial del proyecto es requerido.")
                                                )
                                            ),
                                            "proyeccion_reconocimiento" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "La proyección y reconocimiento nacional o internacional es requerido."),
                                                    "stringLength" => array("max" => 2000,"message" => "Ya cuenta con el máximo de caracteres permitidos, los cuales son 2000.")
                                                )
                                            ),
                                            "impacto_proyecto" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "El impacto que ha tenido el proyecto es requerido."),
                                                    "stringLength" => array("max" => 2000,"message" => "Ya cuenta con el máximo de caracteres permitidos, los cuales son 2000.")
                                                )
                                            )


                                        )
                                    );
                                }
                                else{
                                    $options = array(
                                        "fields" => array(
                                            "localidad" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "La localidad principal en donde el proyecto desarrollará las acciones es requerido.")
                                                )
                                            ),
                                            "nombre" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "El nombre de la propuesta es requerido.")
                                                )
                                            ),
                                            "alianza_sectorial" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "El ¿Proyecto de alianza sectorial? es requerido.")
                                                )
                                            ),
                                            "primera_vez_pdac" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "El ¿Es la primera vez que la propuesta se presenta al PDAC? es requerido.")
                                                )
                                            ),
                                            "relacion_plan[]" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "La relación del proyecto con el Plan de Desarrollo de Bogotá es requerido.")                                                

                                                )
                                            ),
                                            "linea_estrategica[]" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "La línea estratégica del proyecto es requerido.")
                                                )
                                            ),
                                            "area[]" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "El área del proyecto es requerido.")
                                                )
                                            ),
                                            "trayectoria_entidad" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "La trayectoria de la entidad participante es requerido."),
                                                    "stringLength" => array("max" => 2000,"message" => "Ya cuenta con el máximo de caracteres permitidos, los cuales son 2000.")
                                                )
                                            ),
                                            "problema_necesidad" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "El problema o necesidad es requerido."),
                                                    "stringLength" => array("max" => 2000,"message" => "Ya cuenta con el máximo de caracteres permitidos, los cuales son 2000.")
                                                )
                                            ),
                                            "diagnostico_problema" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "El ¿Cómo se diagnosticó el problema o necesidad? es requerido."),
                                                    "stringLength" => array("max" => 2000,"message" => "Ya cuenta con el máximo de caracteres permitidos, los cuales son 2000.")
                                                )
                                            ),
                                            "justificacion" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "La justificacion es requerido."),
                                                    "stringLength" => array("max" => 2000,"message" => "Ya cuenta con el máximo de caracteres permitidos, los cuales son 2000.")
                                                )
                                            ),
                                            "atencedente" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "Los antecedentes generales del proyecto es requerido."),
                                                    "stringLength" => array("max" => 2000,"message" => "Ya cuenta con el máximo de caracteres permitidos, los cuales son 2000.")
                                                )
                                            ),
                                            "objetivo_general" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "El objetivo general es requerido."),
                                                    "stringLength" => array("max" => 1000,"message" => "Ya cuenta con el máximo de caracteres permitidos, los cuales son 1000.")
                                                )
                                            ),
                                            "objetivo_especifico" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "El objetivo específico es requerido."),
                                                    "stringLength" => array("max" => 500,"message" => "Ya cuenta con el máximo de caracteres permitidos, los cuales son 500.")
                                                )
                                            ),
                                            "meta" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "La meta es requerido."),
                                                    "stringLength" => array("max" => 500,"message" => "Ya cuenta con el máximo de caracteres permitidos, los cuales son 500.")
                                                )
                                            ),
                                            "actividad" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "La actividades es requerido.")
                                                )
                                            ),
                                            "metodologia" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "La metodología es requerido."),
                                                    "stringLength" => array("max" => 2000,"message" => "Ya cuenta con el máximo de caracteres permitidos, los cuales son 2000.")
                                                )
                                            ),
                                            "impacto" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "El impacto esperado es requerido."),
                                                    "stringLength" => array("max" => 2000,"message" => "Ya cuenta con el máximo de caracteres permitidos, los cuales son 2000.")
                                                )
                                            ),
                                            "mecanismos_cualitativa" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "El mecanismos de evaluación cualitativa: objetivos e impactos es requerido."),
                                                    "stringLength" => array("max" => 2000,"message" => "Ya cuenta con el máximo de caracteres permitidos, los cuales son 2000.")
                                                )
                                            ),
                                            "mecanismos_cuantitativa" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "El mecanismos de evaluación cuantitativa: cobertura poblacional y territorial es requerido."),
                                                    "stringLength" => array("max" => 2000,"message" => "Ya cuenta con el máximo de caracteres permitidos, los cuales son 2000.")
                                                )
                                            )


                                        )
                                    );
                                }

                                if ($modalidad != 4) {
                                    $options["fields"] += array(
                                        "resumen" => array(
                                            "validators" => array(
                                                "notEmpty" => array("message" => "El resumen de la propuesta es requerido.")
                                            )
                                        ),
                                        "objetivo" => array(
                                            "validators" => array(
                                                "notEmpty" => array("message" => "El objetivo de la propuesta es requerido.")
                                            )
                                        )
                                    );
                                }

                                foreach ($parametros as $k => $v) {
                                    if ($v->obligatorio) {
                                        $options["fields"] += array(
                                            "parametro[" . $v->id . "]" => array(
                                                "validators" => array(
                                                    "notEmpty" => array("message" => "El campo es requerido.")
                                                )
                                            )
                                        );
                                    }
                                }


                                $array["validator"] = $options;

                                $array["upzs"] = array();
                                $array["barrios"] = array();
                                if (isset($propuesta->localidad)) {
                                    $array["upzs"] = Upzs::find("active=true AND localidad=" . $propuesta->localidad);
                                    $array["barrios"] = Barrios::find("active=true AND localidad=" . $propuesta->localidad);
                                }

                                //Registro la accion en el log de convocatorias
                                $logger->info('"token":"{token}","user":"{user}","message":"Retorna en el controlador PropuestasPdac en el método buscar_propuesta, retorna la propuesta (' . $request->get('p') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                $logger->close();

                                //Retorno el array
                                echo json_encode($array);
                            } else {
                                //Registro la accion en el log de convocatorias           
                                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasPdac en el método buscar_propuesta, la propuesta (' . $request->get('p') . ') no existe de la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);                                
                                $logger->close();
                                echo "error_cod_propuesta";
                                exit;
                            }
                        } else {
                            //Registro la accion en el log de convocatorias           
                            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasPdac en el método buscar_propuesta, la propuesta (' . $request->get('p') . ') no existe de la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);                                
                            $logger->close();
                            echo "error_cod_propuesta";
                            exit;
                        }
                    } else {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasPdac en el método buscar_propuesta, Debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "crear_perfil";
                        exit;
                    }
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasPdac en el método buscar_propuesta, Debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);                                        
                    $logger->close();
                    echo "crear_perfil";
                    exit;
                }
            } else {
                //Registro la accion en el log de convocatorias    
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasPdac en el método buscar_propuesta, acceso denegado en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);                                                                        
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias        
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasPdac en el método buscar_propuesta, token caduco en la convocatoria(' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);            
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasPdac en el método buscar_propuesta, en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => '', 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->get('/buscar_propuesta_visualizar_formulario', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo buscar_propuesta como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
                //Consulto la convocatoria
                $convocatoria = Convocatorias::findFirst($request->get('id'));

                //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
                $nombre_convocatoria = $convocatoria->nombre;
                $nombre_categoria = "";
                $modalidad = $convocatoria->modalidad;
                if ($convocatoria->convocatoria_padre_categoria > 0) {
                    $nombre_convocatoria = $convocatoria->getConvocatorias()->nombre;
                    $nombre_categoria = $convocatoria->nombre;
                    $modalidad = $convocatoria->getConvocatorias()->modalidad;
                }

                //Consulto los parametros adicionales para el formulario de la propuesta
                $conditions = ['convocatoria' => $convocatoria->id, 'active' => true];
                $parametros = Convocatoriaspropuestasparametros::find(([
                    'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                    'bind' => $conditions,
                    'order' => 'orden ASC',
                ]));
                
                //Creo el array de la propuesta
                $array = array();
                $array["estado"] = "";
                $array["propuesta"]["nombre_participante"] = "";
                $array["propuesta"]["tipo_participante"] = "";
                $array["propuesta"]["nombre_convocatoria"] = $nombre_convocatoria;
                $array["propuesta"]["nombre_categoria"] = $nombre_categoria;
                $array["propuesta"]["modalidad"] = $modalidad;
                $array["propuesta"]["estado"] = "";
                $array["propuesta"]["nombre"] = "";
                $array["propuesta"]["resumen"] = "";
                $array["propuesta"]["objetivo"] = "";
                $array["propuesta"]["bogota"] = true;
                $array["propuesta"]["localidad"] = "";
                $array["propuesta"]["upz"] = "";
                $array["propuesta"]["barrio"] = "";
                $array["propuesta"]["ejecucion_menores_edad"] = true;
                $array["propuesta"]["porque_medio"] = "";
                $array["propuesta"]["id"] = "";
                $array["localidades"] = Localidades::find("active=true");
                $array["parametros"] = $parametros;
                $tabla_maestra = Tablasmaestras::find("active=true AND nombre='medio_se_entero'");
                $array["medio_se_entero"] = explode(",", $tabla_maestra[0]->valor);

                //Creo los parametros obligatorios del formulario
                $options = array(
                    "fields" => array(
                        "nombre" => array(
                            "validators" => array(
                                "notEmpty" => array("message" => "El nombre de la propuesta es requerido.")
                            )
                        ),
                        "porque_medio[]" => array(
                            "validators" => array(
                                "notEmpty" => array("message" => "El medio por el cual se enteró de esta convocatoria es requerido.")
                            )
                        )
                    )
                );

                if ($modalidad != 4) {
                    $options["fields"] += array(
                        "resumen" => array(
                            "validators" => array(
                                "notEmpty" => array("message" => "El resumen de la propuesta es requerido.")
                            )
                        ),
                        "objetivo" => array(
                            "validators" => array(
                                "notEmpty" => array("message" => "El objetivo de la propuesta es requerido.")
                            )
                        )
                    );
                }

                foreach ($parametros as $k => $v) {
                    if ($v->obligatorio) {
                        $options["fields"] += array(
                            "parametro[" . $v->id . "]" => array(
                                "validators" => array(
                                    "notEmpty" => array("message" => "El campo es requerido.")
                                )
                            )
                        );
                    }
                }


                $array["validator"] = $options;

                $array["upzs"] = array();
                $array["barrios"] = array();
                if (isset($propuesta->localidad)) {
                    $array["upzs"] = Upzs::find("active=true AND localidad=" . $propuesta->localidad);
                    $array["barrios"] = Barrios::find("active=true AND localidad=" . $propuesta->localidad);
                }

                //Registro la accion en el log de convocatorias
                $logger->info('"token":"{token}","user":"{user}","message":"Retorno en el metodo buscar_propuesta como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();

                //Retorno el array
                echo json_encode($array);
                    
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo buscar_propuesta como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo buscar_propuesta como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo buscar_propuesta como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->post('/editar_propuesta', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestasPdac en el método editar_propuesta, para editar la propuesta (' . $request->getPut('id') . ') como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
            
            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
                //parametros de la peticion
                $post = $app->request->getPost();
                $propuesta = Propuestas::findFirst($post["id"]);
                $post["porque_medio"] = json_encode($post["porque_medio"]);
                $post["relacion_plan"] = json_encode($post["relacion_plan"]);
                $post["linea_estrategica"] = json_encode($post["linea_estrategica"]);
                $post["alcance_territorial"] = json_encode($post["alcance_territorial"]);
                $post["area"] = json_encode($post["area"]);
                $post["actualizado_por"] = $user_current["id"];
                $post["fecha_actualizacion"] = date("Y-m-d H:i:s");
                
                if($post["localidad"]=="")
                {
                    $post["localidad"]=null;
                }
                
                if ($propuesta->save($post) === false) {
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasPdac en el método editar_propuesta, error al editar la propuesta (' . $request->getPut('id') . ') como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);                    
                    $logger->close();
                    echo "error";
                } else {

                    //Recorrmos los parametros dinamicos                    
                    foreach ($post["parametro"] as $k => $v) {
                        //Consulto si exite el parametro a la propuestas
                        $parametro_actual = Propuestasparametros::findFirst("convocatoriapropuestaparametro=" . $k . " AND propuesta = " . $propuesta->id);
                        if (isset($parametro_actual->id)) {
                            $parametro = $parametro_actual;
                        } else {
                            $parametro = new Propuestasparametros();
                        }

                        //Cargo lo valores actuales
                        $array_save = array();
                        $array_save["convocatoriapropuestaparametro"] = $k;
                        $array_save["propuesta"] = $propuesta->id;
                        $array_save["valor"] = $v;

                        //Valido si existe para relacionar los campos de usuario
                        if (isset($parametro->id)) {
                            $parametro->actualizado_por = $user_current["id"];
                            $parametro->fecha_actualizacion = date("Y-m-d H:i:s");
                        } else {
                            $parametro->creado_por = $user_current["id"];
                            $parametro->fecha_creacion = date("Y-m-d H:i:s");
                        }

                        //Guardo los parametros de la convocatoria
                        if ($parametro->save($array_save) == false) {
                            foreach ($parametro->getMessages() as $message) {
                                $logger->info('"token":"{token}","user":"{user}","message":"Se genero un error al editar el parametro (' . $parametro->id . ') en la propuesta (' . $post["id"] . ') como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')" (' . $message . ').', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            }
                        } else {
                            $logger->info('"token":"{token}","user":"{user}","message":"Se edito con exito el parametro (' . $parametro->id . ') en la propuesta (' . $post["id"] . ') como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        }
                    }

                    //Registro la accion en el log de convocatorias
                    $logger->info('"token":"{token}","user":"{user}","message":"Retorno en el controlador PropuestasPdac en el método editar_propuesta, se edito la propuesta (' . $request->getPut('id') . ') con exito como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);                                        
                    $logger->close();
                    echo $propuesta->id;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasPdac en el método editar_propuesta, acceso denegado en la propuesta (' . $request->getPut('id') . ') como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);                    
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasPdac en el método editar_propuesta, token caduco en la propuesta (' . $request->getPut('id') . ') como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => '', 'token' => $request->getPut('token')]);                                
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias                   
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasPdac en el método editar_propuesta, error en el metodo en la propuesta (' . $request->getPut('id') . ') como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ') ' . $ex->getMessage() . '"', ['user' => '', 'token' => $request->getPut('token')]);                                
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->post('/editar_propuesta_territorio', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestasPdac en el método editar_propuesta_territorio, ingresa a editar territorio como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
        
            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
                //parametros de la peticion
                $post = $app->request->getPost();
                $propuesta = Propuestas::findFirst($post["id"]);
                $post["localidades"] = json_encode($post["localidades"]);
                $post["actualizado_por"] = $user_current["id"];
                $post["fecha_actualizacion"] = date("Y-m-d H:i:s");
                
                if ($propuesta->save($post) === false) {
                    $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasPdac en el método editar_propuesta_territorio, error al editar territorio de la propuesta (' . $post["id"] . ') como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "error";
                } else {
                    //Variables del territorio
                    $array_territorio=array(
                                            "femenino",
                                            "intersexual",
                                            "masculino",
                                            "primera_infancia",
                                            "infancia",
                                            "adolescencia",
                                            "juventud",
                                            "adulto",
                                            "adulto_mayor",
                                            "estrato_1",
                                            "estrato_2",
                                            "estrato_3",
                                            "estrato_4",
                                            "estrato_5",
                                            "estrato_6",
                                            "comunidades_negras_afrocolombianas",
                                            "comunidad_raizal",
                                            "pueblos_comunidades_indigenas",
                                            "pueblo_rom_gitano",
                                            "mestizo",
                                            "ninguno_etnico",
                                            "artesanos",
                                            "discapacitados",
                                            "habitantes_calle",
                                            "lgbti",
                                            "personas_comunidades_rurales_campesinas",
                                            "personas_privadas_libertad",
                                            "victimas_conflicto",
                                            "ninguno_grupo"
                                            );
                    
                    foreach($array_territorio as $clave => $valor)
                    {
                        //Consulto la propuesta territorio
                        $conditions = ['propuesta' => $propuesta->id, 'variable' => $valor];
                        $array_territorio = Propuestasterritorios::findFirst(([
                                    'conditions' => 'propuesta=:propuesta: AND variable=:variable:',
                                    'bind' => $conditions,
                        ]));
                        
                        if(isset($array_territorio->id))
                        {
                            
                            $array_formulario=array(
                                                "variable"=>$valor,
                                                "valor"=>$post[$valor],
                                                "propuesta"=>$propuesta->id,
                                                "actualizado_por" => $user_current["id"],
                                                "fecha_actualizacion" => date("Y-m-d H:i:s")
                                                );
                        }
                        else
                        {
                            $array_territorio=new Propuestasterritorios();
                            
                            $array_formulario=array(
                                                    "variable"=>$valor,
                                                    "valor"=>$post[$valor],
                                                    "propuesta"=>$propuesta->id,
                                                    "creado_por" => $user_current["id"],
                                                    "fecha_creacion" => date("Y-m-d H:i:s")
                                                    );
                            
                        }
                        
                        if ($array_territorio->save($array_formulario) === false) {
                            foreach ($array_territorio->getMessages() as $message) {                              
                              $logger->error('"token":"{token}","user":"{user}","message":"Se genero un error al editar la propuesta (' . $post["id"] . '), parametro de territorio (' . $message . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            }
                        }                        
                    }
                    
                    //Registro la accion en el log de convocatorias                    
                    $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestasPdac en el método editar_propuesta_territorio, se edito el territorio con exito como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);                    
                    $logger->close();                    
                    echo $propuesta->id;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasPdac en el método editar_propuesta_territorio, acceso denegado como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasPdac en el método editar_propuesta_territorio, token caduco como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasPdac en el método editar_propuesta_territorio, error metodo como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->post('/validar_periodo_ejecucion', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestaPdac en el método validar_periodo_ejecucion, validar periodo de ejecucicion de la propuesta (' . $request->getPut('p') . ')"', ['user' => $user_current['username'], 'token' => $request->getPut('token')]);
        
            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
                //Consulto la convocatoria
                $id=$request->getPut('conv');
                $convocatoria = Convocatorias::findFirst($id);

                //Si la convocatoria seleccionada es categoria y no es especial invierto los id
                if ($convocatoria->convocatoria_padre_categoria > 0 && $convocatoria->getConvocatorias()->tiene_categorias == true && $convocatoria->getConvocatorias()->diferentes_categorias == false) {
                    $id = $convocatoria->getConvocatorias()->id;                    
                }                
                
                //Consulto la fecha de cierre del cronograma de la convocatoria
                $conditions = ['convocatoria' => $id, 'active' => true, 'tipo_evento' => 27];
                $fecha_ejecucion_real = Convocatoriascronogramas::findFirst(([
                            'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_evento=:tipo_evento:',
                            'bind' => $conditions,
                ]));
                
                //Definos las fechas
                $time = strtotime($request->getPut('fecha'));
                $fecha = date('Y-m-d',$time);
                $fecha_actual = strtotime($fecha, time());                
                $fecha_inicio = strtotime($fecha_ejecucion_real->fecha_inicio, time());                
                $fecha_fin = strtotime($fecha_ejecucion_real->fecha_fin, time());
                
                if (($fecha_actual >= $fecha_inicio) && ($fecha_actual <= $fecha_fin))
                {
                    //Registro la accion en el log de convocatorias
                    $logger->info('"token":"{token}","user":"{user}","message":"Retorna en el controlador PropuestaPdac en el método validar_periodo_ejecucion, La fecha de ejecucion del cronograma es correcta en la propuesta (' . $request->getPut('p') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();                    
                    echo true;                                   
                }
                else
                {
                    //Registro la accion en el log de convocatorias
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método validar_periodo_ejecucion, La fecha de ejecucion del cronograma no es correcta en la propuesta (' . $request->getPut('p') . ')"', ['user' => $user_current['username'], 'token' => $request->getPut('token')]);                    
                    $logger->close();                    
                    echo false; 
                }
                
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método validar_periodo_ejecucion, acceso denegado en la propuesta (' . $request->getPut('p') . ')"', ['user' => $user_current['username'], 'token' => $request->getPut('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método validar_periodo_ejecucion, token caduco en la propuesta (' . $request->getPut('p') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método validar_periodo_ejecucion, error metodo en la propuesta (' . $request->getPut('p') . ')"' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->post('/editar_propuesta_objetivo', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestasPdac en el método editar_propuesta_objetivo, ingresa a editar el objetivo general como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
            
            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
                //parametros de la peticion
                $post = $app->request->getPost();
                $propuesta = Propuestas::findFirst($post["id"]);
                $post["actualizado_por"] = $user_current["id"];
                $post["fecha_actualizacion"] = date("Y-m-d H:i:s");
                
                if ($propuesta->save($post) === false) {
                    $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasPdac en el método editar_propuesta_objetivo, error al editar el objetivo general de la propuesta (' . $post["id"] . ') como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "error";
                } else {
                    //Registro la accion en el log de convocatorias                    
                    $logger->info('"token":"{token}","user":"{user}","message":"Retorna al controlador PropuestasPdac en el método editar_propuesta_objetivo, edito el objetivo general con exito de la propuesta (' . $post["id"] . ') como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();                    
                    echo $propuesta->id;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasPdac en el método editar_propuesta_objetivo, acceso denegado como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasPdac en el método editar_propuesta_objetivo, token caduco como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasPdac en el método editar_propuesta_objetivo, error metodo como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->post('/editar_propuesta_objetivo_especifico', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestasPdac en el método editar_propuesta_objetivo_especifico, ingreso a crear o editar el objetivo especifico como (' . $request->getPut('m') . ') en la propuesta(' . $request->getPut('propuesta') . ')"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);                
        
            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {                
                //parametros de la peticion
                $post = $app->request->getPost();
                
                $propuesta_documento = Propuestasobjetivos::findFirst($post["id"]);
                
                $propuesta = Propuestas::findFirst($post["propuesta"]);
                
                if($propuesta->estado==7)
                {
                    if($post["id"]!="")
                    {
                        $validar_total_objetivos=false;
                        $post["actualizado_por"] = $user_current["id"];
                        $post["fecha_actualizacion"] = date("Y-m-d H:i:s");
                    }
                    else
                    {
                        $validar_total_objetivos=true;
                        $propuesta_documento = new Propuestasobjetivos();
                        $post["creado_por"] = $user_current["id"];
                        $post["fecha_creacion"] = date("Y-m-d H:i:s");
                    }                                 
                    
                    $guardar_objetivo=true;
                    if($validar_total_objetivos)
                    {
                        //consulto si tiene el maximo de objetivos
                        $total_objetivos = Propuestasobjetivos::find("propuesta=".$post["propuesta"]);                        
                        $post["orden"] = count($total_objetivos)+1;
                        //maximo_objetivos_especificos
                        $tabla_maestra = Tablasmaestras::find("active=true AND nombre='maximo_objetivos_especificos'");
                        $maximo_objetivos_especificos = $tabla_maestra[0]->valor;
                        
                        if(count($total_objetivos)<=$maximo_objetivos_especificos)
                        {
                            $guardar_objetivo=true;
                        }
                        else
                        {
                            $guardar_objetivo=false;
                        }                        
                    }
                    
                    if( $guardar_objetivo )
                    {
                        if ($propuesta_documento->save($post) === false) {                            
                            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasPdac en el método editar_propuesta_objetivo_especifico, se genero un error al editar o crear el objetivo especifico como (' . $request->getPut('m') . ') en la propuesta(' . $request->getPut('propuesta') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "error";
                        } else {
                            //Registro la accion en el log de convocatorias
                            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestasPdac en el método editar_propuesta_objetivo_especifico, se edito o creo el objetivo especifico con exito como (' . $request->getPut('m') . ') en la propuesta(' . $request->getPut('propuesta') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();                    
                            echo $propuesta_documento->id;
                        }
                    }
                    else
                    {
                        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasPdac en el método editar_propuesta_objetivo_especifico, ya cuenta con el maximo de objetivos especificos como (' . $request->getPut('m') . ') en la propuesta(' . $request->getPut('propuesta') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "error_maximo_objetivos";
                    }
                    
                }
                else
                {
                   //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasPdac en el método editar_propuesta_objetivo_especifico, la propuesta ya esta inscrita como (' . $request->getPut('m') . ') en la propuesta(' . $request->getPut('propuesta') . ')"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();
                    echo "acceso_denegado"; 
                }
                
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasPdac en el método editar_propuesta_objetivo_especifico, acceso denegado como (' . $request->getPut('m') . ') en la propuesta(' . $request->getPut('propuesta') . ')"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo editar_propuesta como (' . $request->getPut('m') . ') en la propuesta(' . $request->getPut('propuesta') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo editar_propuesta como (' . $request->getPut('m') . ') en la propuesta(' . $request->getPut('propuesta') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->post('/editar_propuesta_actividad', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestaPdac en el método editar_propuesta_actividad, ingresa a crear o editar la actividad como (' . $request->getPut('m') . ') en la propuesta(' . $request->getPut('propuesta') . ')"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);        
                
            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {                
                //parametros de la peticion
                $post = $app->request->getPost();
                $propuesta_actividad = Propuestasactividades::findFirst($post["id"]);
                
                $propuesta = Propuestas::findFirst($post["propuesta"]);
                
                if($propuesta->estado==7)
                {
                    if(isset($propuesta_actividad->id))
                    {
                        $post["actualizado_por"] = $user_current["id"];
                        $post["fecha_actualizacion"] = date("Y-m-d H:i:s");
                    }
                    else
                    {
                        $propuesta_actividad = new Propuestasactividades();
                        $post["creado_por"] = $user_current["id"];
                        $post["fecha_creacion"] = date("Y-m-d H:i:s");
                    }                                                

                    if ($propuesta_actividad->save($post) === false) {
                        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método editar_propuesta_actividad, error al editar la actividad (' . $post["id"] . ') como (' . $request->getPut('m') . ') en la propuesta(' . $request->getPut('propuesta') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "error";
                    } else {
                        //Registro la accion en el log de convocatorias
                        $logger->info('"token":"{token}","user":"{user}","message":"Retorno en el controlador PropuestaPdac en el método editar_propuesta_actividad, se edito con exito la actividad (' . $post["id"] . ') como (' . $request->getPut('m') . ') en la propuesta(' . $request->getPut('propuesta') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();                    
                        echo $propuesta_actividad->id;
                    }
                }
                else
                {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método editar_propuesta_actividad, la propuesta ya esta inscrita como (' . $request->getPut('m') . ') en la propuesta(' . $request->getPut('propuesta') . ')"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();
                    echo "acceso_denegado";
                }
                
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método editar_propuesta_actividad, acceso denegado como (' . $request->getPut('m') . ') en la propuesta(' . $request->getPut('propuesta') . ')"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método editar_propuesta_actividad, token caduco como (' . $request->getPut('m') . ') en la propuesta(' . $request->getPut('propuesta') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método editar_propuesta_actividad, error metodo como (' . $request->getPut('m') . ') en la propuesta(' . $request->getPut('propuesta') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->post('/editar_propuesta_cronograma', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestaPdac en el método editar_propuesta_cronograma, ingresa a crear o editar el cronograma como (' . $request->getPut('m') . ') en la propuesta(' . $request->getPut('propuesta') . ')"', ['user' => $user_current['username'], 'token' => $request->getPut('token')]);        
        
            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {                
                //parametros de la peticion
                $post = $app->request->getPost();
                $propuesta_cronograma = Propuestascronogramas::findFirst($post["id"]);
                
                $propuesta = Propuestas::findFirst($post["propuesta"]);
                
                if($propuesta->estado==7)
                {
                
                    $where_id="";
                    if(isset($propuesta_cronograma->id))
                    {
                        $where_id=" AND id<>".$propuesta_cronograma->id;                    
                        $post["actualizado_por"] = $user_current["id"];
                        $post["fecha_actualizacion"] = date("Y-m-d H:i:s");
                    }
                    else
                    {
                        $propuesta_cronograma = new Propuestascronogramas();
                        $post["creado_por"] = $user_current["id"];
                        $post["fecha_creacion"] = date("Y-m-d H:i:s");
                        $post["etapa"] = "Registro";
                    }                                                

                    $validar_propuesta_cronograma = Propuestascronogramas::findFirst("fecha='".$post["fecha"]."' AND propuestaactividad=".$post["propuestaactividad"].$where_id);

                    if(isset($validar_propuesta_cronograma->id))
                    {
                        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método editar_propuesta_cronograma, error crear el cronograma, ya cuenta con la semana de ejecución, en la propuesta (' . $post["propuesta"] . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "error_fecha";
                    }
                    else
                    {
                        if ($propuesta_cronograma->save($post) === false) {
                            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método editar_propuesta_cronograma, se genero un error al editar o crea el cronograma (' . $post["id"] . ') como (' . $request->getPut('m') . ') en la propuesta (' . $request->getPut('propuesta') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "error";
                        } else {
                            //Registro la accion en el log de convocatorias
                            $logger->info('"token":"{token}","user":"{user}","message":"Retorno en el controlador PropuestaPdac en el método editar_propuesta_cronograma, se edito con exito el cronograma (' . $post["id"] . ') como (' . $request->getPut('m') . ') en la propuesta (' . $request->getPut('propuesta') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();                    
                            echo $propuesta_cronograma->id;
                        }
                    }        
                }
                else
                {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método editar_propuesta_cronograma, la propuesta ya esta inscrita como (' . $request->getPut('m') . ') en la propuesta(' . $request->getPut('propuesta') . ')"', ['user' => $user_current['username'], 'token' => $request->getPut('token')]);
                    $logger->close();
                    echo "acceso_denegado";    
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método editar_propuesta_cronograma, acceso denegado como (' . $request->getPut('m') . ') en la propuesta(' . $request->getPut('propuesta') . ')"', ['user' => $user_current['username'], 'token' => $request->getPut('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método editar_propuesta_cronograma, token caduco como (' . $request->getPut('m') . ') en la propuesta(' . $request->getPut('propuesta') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método editar_propuesta_cronograma, error metodo como (' . $request->getPut('m') . ') en la propuesta (' . $request->getPut('propuesta') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->post('/editar_propuesta_presupuesto', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);
            
            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestaPdac en el método editar_propuesta_presupuesto, ingresa a crear o editar presupuesto como (' . $request->getPut('m') . ') en la propuesta(' . $request->getPut('propuesta') . ')"', ['user' => $user_current['username'], 'token' => $request->getPut('token')]);        
        
            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {                
                //parametros de la peticion
                $post = $app->request->getPost();
                $propuesta_presupuesto = Propuestaspresupuestos::findFirst($post["id"]);
                
                $propuesta = Propuestas::findFirst($post["propuesta"]);
                
                if($propuesta->estado==7)
                {
                
                    if(isset($propuesta_presupuesto->id))
                    {
                        $post["actualizado_por"] = $user_current["id"];
                        $post["fecha_actualizacion"] = date("Y-m-d H:i:s");
                    }
                    else
                    {
                        $propuesta_presupuesto = new Propuestaspresupuestos();
                        $post["creado_por"] = $user_current["id"];
                        $post["fecha_creacion"] = date("Y-m-d H:i:s");                    
                    }                                                

                    if ($propuesta_presupuesto->save($post) === false) {
                        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método editar_propuesta_presupuesto, error al crear o editar el presupuesto (' . $post["id"] . ') como (' . $request->getPut('m') . ') en la propuesta(' . $request->getPut('propuesta') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "error";
                    } else {
                        //Registro la accion en el log de convocatorias
                        $logger->info('"token":"{token}","user":"{user}","message":"Retorno en el controlador PropuestaPdac en el método editar_propuesta_presupuesto, se creo o edito con exito el presupuesto (' . $post["id"] . ') como (' . $request->getPut('m') . ') en la propuesta(' . $request->getPut('propuesta') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();                    
                        echo $propuesta_presupuesto->id;
                    }
                }
                else
                {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método editar_propuesta_presupuesto, la propuesta ya esta inscrita como (' . $request->getPut('m') . ') en la propuesta(' . $request->getPut('propuesta') . ')"', ['user' => $user_current['username'], 'token' => $request->getPut('token')]);
                    $logger->close();
                    echo "acceso_denegado";
                }
                                                               
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método editar_propuesta_presupuesto, acceso denegado como (' . $request->getPut('m') . ') en la propuesta(' . $request->getPut('propuesta') . ')"', ['user' => $user_current['username'], 'token' => $request->getPut('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método editar_propuesta_presupuesto, token caduco como (' . $request->getPut('m') . ') en la propuesta(' . $request->getPut('propuesta') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método editar_propuesta_presupuesto, error metodo como (' . $request->getPut('m') . ') en la propuesta(' . $request->getPut('propuesta') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->post('/inscribir_propuesta', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo inscribir_propuesta como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));
            
            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
                //Consulto la convocatoria
                $id=$request->getPut('conv');
                $convocatoria = Convocatorias::findFirst($id);

                //Si la convocatoria seleccionada es categoria y no es especial invierto los id
                if ($convocatoria->convocatoria_padre_categoria > 0 && $convocatoria->getConvocatorias()->tiene_categorias == true && $convocatoria->getConvocatorias()->diferentes_categorias == false) {
                    $id = $convocatoria->getConvocatorias()->id;                    
                }                
                
                //Consulto la fecha de cierre del cronograma de la convocatoria
                $conditions = ['convocatoria' => $id, 'active' => true, 'tipo_evento' => 12];
                $fecha_cierre_real = Convocatoriascronogramas::findFirst(([
                            'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_evento=:tipo_evento:',
                            'bind' => $conditions,
                ]));
                $fecha_actual = strtotime(date("Y-m-d H:i:s"), time());
                
                //Consulto la propuesta actual
                $propuesta = Propuestas::findFirst($request->getPut('id'));
                
                //Valido que sea la fecha del cronograma o la fecha de habilitada
                if($propuesta->habilitar)
                {
                    $fecha_cierre = strtotime($propuesta->habilitar_fecha_fin, time());
                }
                else
                {
                    $fecha_cierre = strtotime($fecha_cierre_real->fecha_fin, time());
                }
                
                if ($fecha_actual > $fecha_cierre) {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"La convocatoria(' . $request->getPut('conv') . ') no esta activa, la fecha de cierre es (' . $fecha_cierre_real->fecha_fin . ')", en el metodo inscribir_propuesta', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "error_fecha_cierre";
                } else {
                    
                    //Valido si se habilita propuesta por derecho de petición
                    $estado_actual=$propuesta->estado;
                    if($propuesta->habilitar)
                    {
                        $fecha_actual = strtotime(date("Y-m-d H:i:s"), time());
                        $habilitar_fecha_inicio = strtotime($propuesta->habilitar_fecha_inicio, time());
                        $habilitar_fecha_fin = strtotime($propuesta->habilitar_fecha_fin, time());
                        if (($fecha_actual >= $habilitar_fecha_inicio) && ($fecha_actual <= $habilitar_fecha_fin))
                        {
                            $estado_actual = 7;                                    
                        }
                    } 
                    
                    if ($estado_actual == 7) {

                        //Consulto el total de propuesta con el fin de generar el codigo de la propuesta
                        $sql_total_propuestas = "SELECT 
                                                    COUNT(p.id) as total_propuestas
                                            FROM Propuestas AS p                                
                                            WHERE
                                            p.estado IN (8,20,21,22,23,24,31,33,34) AND p.codigo <> '' AND p.convocatoria=" . $convocatoria->id;

                        $total_propuesta = $app->modelsManager->executeQuery($sql_total_propuestas)->getFirst();
                        $codigo_propuesta = $convocatoria->id . "-" . (str_pad($total_propuesta->total_propuestas + 1, 3, "0", STR_PAD_LEFT));

                        $post["estado"] = 8;
                        $post["actualizado_por"] = $user_current["id"];
                        $post["fecha_actualizacion"] = date("Y-m-d H:i:s");
                        $post["fecha_inscripcion"] = date("Y-m-d H:i:s");
                        $post["habilitar"] = FALSE;
                        $propuesta->codigo = $codigo_propuesta;

                        if ($propuesta->save($post) === false) {
                            $logger->error('"token":"{token}","user":"{user}","message":"Se genero un error al editar la propuesta (' . $post["id"] . ') como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "error";
                        } else {

                            //Registro la accion en el log de convocatorias
                            $logger->info('"token":"{token}","user":"{user}","message":"Se inscribio la propuesta con exito (' . $post["id"] . ') como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo $propuesta->id;
                        }
                    } else {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"La propuesta (' . $request->getPut('id') . ') no esta en estado Registrada en el metodo inscribir_propuesta"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "error_estado";
                    }
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo inscribir_propuesta como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo inscribir_propuesta como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo inscribir_propuesta como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->post('/subsanar_propuesta', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo subsanar_propuesta como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
                //Consulto la convocatoria
                $id=$request->getPut('conv');
                $convocatoria = Convocatorias::findFirst($id);

                //Si la convocatoria seleccionada es categoria y no es especial invierto los id
                if ($convocatoria->convocatoria_padre_categoria > 0 && $convocatoria->getConvocatorias()->tiene_categorias == true && $convocatoria->getConvocatorias()->diferentes_categorias == false) {
                    $id = $convocatoria->getConvocatorias()->id;                    
                }                
                                
                //Consulto la propuesta
                $propuesta = Propuestas::findFirst($request->getPut('id'));
                
                $fecha_actual = strtotime(date("Y-m-d H:i:s"), time());
                $fecha_inicio_subsanacion = strtotime($propuesta->fecha_inicio_subsanacion, time());
                $fecha_fin_subsanacion = strtotime($propuesta->fecha_fin_subsanacion, time());
                
                if (($fecha_actual >= $fecha_inicio_subsanacion) && ($fecha_actual <= $fecha_fin_subsanacion))
                {
                    if ($propuesta->estado == 22) {

                        $post["estado"] = 31;
                        $post["fecha_subsanacion"] = date("Y-m-d H:i:s");                        

                        if ($propuesta->save($post) === false) {
                            $logger->error('"token":"{token}","user":"{user}","message":"Se genero un error al editar la propuesta (' . $post["id"] . ') como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "error";
                        } else {

                            //Registro la accion en el log de convocatorias
                            $logger->info('"token":"{token}","user":"{user}","message":"Se inscribio la propuesta con exito (' . $post["id"] . ') como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo $propuesta->id;
                        }
                    } else {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"La propuesta (' . $request->getPut('id') . ') no esta en estado Subsanación Recibida en el metodo subsanar_propuesta"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "error_estado";
                    }
                                        
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"La convocatoria(' . $request->getPut('conv') . ') no esta activa, el periodo de subsanacion es  (' . $propuesta->fecha_inicio_subsanacion . ' a ' . $propuesta->fecha_fin_subsanacion . ')", en el metodo subsanar_propuesta', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "error_fecha_cierre";                   
                    
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo subsanar_propuesta como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo subsanar_propuesta como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo subsanar_propuesta como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->post('/anular_propuesta', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo anular_propuesta"', ['user' => '', 'token' => $request->getPost('token')]);

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
                
                //Consulto la propuesta
                $propuesta = Propuestas::findFirst($request->getPost('propuesta'));
                
                //Consulto la convocatoria
                $convocatoria = Convocatorias::findFirst($propuesta->convocatoria);
                $id_convocatoria=$convocatoria->id;
                //Si la convocatoria seleccionada es categoria y no es especial invierto los id
                if ($convocatoria->convocatoria_padre_categoria > 0 && $convocatoria->getConvocatorias()->tiene_categorias == true && $convocatoria->getConvocatorias()->diferentes_categorias == false) {
                    $id_convocatoria = $convocatoria->getConvocatorias()->id;                    
                }
                                
                //Consulto la fecha de cierre del cronograma de la convocatoria
                $conditions = ['convocatoria' => $id_convocatoria, 'active' => true, 'tipo_evento' => 12];
                $fecha_cierre_real = Convocatoriascronogramas::findFirst(([
                            'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_evento=:tipo_evento:',
                            'bind' => $conditions,
                ]));
                
                //saco las fechas
                $fecha_actual = strtotime(date("Y-m-d H:i:s"), time());
                $fecha_cierre = strtotime($fecha_cierre_real->fecha_fin, time());
                
                if ($fecha_actual > $fecha_cierre) {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Se genero un error no puede anular la propuesta (' . $request->getPost('propuesta') . '), ya que esta cerrada la convocatoria, en el metodo anular_propuesta"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "error_fecha_cierre";
                } else {
                    //Solo puede anular si esta en estado Guardada - No Inscrita, Inscrita
                    if ( $propuesta->estado == 7 || $propuesta->estado == 8 ) {

                        //Consulto el total de propuesta con el fin de generar el codigo de la propuesta

                        $post["estado"] = 20;
                        $post["codigo"] = "Anulada ".$propuesta->codigo;
                        $post["justificacion_anulacion"] = $request->getPost('justificacion_anulacion');
                        $post["actualizado_por"] = $user_current["id"];
                        $post["fecha_actualizacion"] = date("Y-m-d H:i:s");                    

                        if ($propuesta->save($post) === false) {
                            $logger->error('"token":"{token}","user":"{user}","message":"Se genero un error al editar la propuesta (' . $request->getPost('propuesta') . ') en el metodo anular_propuesta"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "error";
                        } else {
                            //Registro la accion en el log de convocatorias
                            $logger->info('"token":"{token}","user":"{user}","message":"Se anulo la propuesta con exito (' . $request->getPost('propuesta') . ') en el metodo anular_propuesta."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo $propuesta->id;
                        }
                    } else {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"La propuesta (' . $request->getPost('propuesta') . ') no esta en estado Registrada en el metodo anular_propuesta"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "error_estado";
                    }
                }
               
                
                
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo anular_propuesta al anular la propuesta (' . $request->getPut('propuesta') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo anular_propuesta al anular la propuesta (' . $request->getPut('propuesta') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo anular_propuesta al anular la propuesta (' . $request->getPost('propuesta') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

// Carga los objetivos especificios de la propuesta
$app->get('/cargar_tabla_objetivos', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);
            
            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestaPdac en el método cargar_tabla_objetivos, ingresa a cargar tabla de objetivos como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
            
            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
                //Consulto la propuesta solicitada
                $conditions = ['id' => $request->get('p'), 'active' => true];
                $propuesta = Propuestas::findFirst(([
                            'conditions' => 'id=:id: AND active=:active:',
                            'bind' => $conditions,
                ]));

                //Defino columnas para el orden desde la tabla html
                $columns = array(
                    0 => 'p.objetivo',
                    1 => 'p.meta',
                    2 => 'p.id',
                    3 => 'p.orden'                    
                );
                
                $where .= " WHERE p.propuesta = " . $propuesta->id;
                //Condiciones para la consulta

                if (!empty($request->get("search")['value'])) {
                    $where .= " AND ( UPPER(" . $columns[0] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                    $where .= " OR UPPER(" . $columns[1] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' )";
                }

                if($propuesta->estado==7)
                {
                    $check="concat('<input title=\"',p.id,'\" type=\"checkbox\" class=\"check_activar_',p.active,' activar_objetivo\" />') as activar_registro";
                }
                else
                {
                    $check="concat('<input disabled title=\"',p.id,'\" type=\"checkbox\" class=\"check_activar_',p.active,' activar_objetivo\" />') as activar_registro";
                }
                
                //Defino el sql del total y el array de datos
                $sqlTot = "SELECT count(*) as total FROM Propuestasobjetivos AS p";
                $sqlRec = "SELECT " . $columns[0] . "," . $columns[1] . "," . $columns[3] . ",concat('<button title=\"',p.id,'\" type=\"button\" class=\"btn btn-warning cargar_formulario\" data-toggle=\"modal\" data-target=\"#nuevo_objetivo\"><span class=\"glyphicon glyphicon-edit\"></span></button>') as editar, ".$check." FROM Propuestasobjetivos AS p";

                //concarnar search sql if value exist
                if (isset($where) && $where != '') {

                    $sqlTot .= $where;
                    $sqlRec .= $where;
                }

                //Concarno el orden y el limit para el paginador
                $sqlRec .= " ORDER BY p.objetivo";

                //ejecuto el total de registros actual
                $totalRecords = $app->modelsManager->executeQuery($sqlTot)->getFirst();

                //creo el array
                $json_data = array(
                    "draw" => intval($request->get("draw")),
                    "recordsTotal" => intval($totalRecords["total"]),
                    "recordsFiltered" => intval($totalRecords["total"]),
                    "data" => $app->modelsManager->executeQuery($sqlRec)   // total data array
                );
                
                $logger->info('"token":"{token}","user":"{user}","message":"Retorna en el controlador PropuestaPdac en el método cargar_tabla_objetivos, retorna la tabla de objetivos como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();
                
                //retorno el array en json
                echo json_encode($json_data);
            } else {
                //Registro la accion en el log de convocatorias
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método cargar_tabla_objetivos, acceso denegado como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método cargar_tabla_objetivos, token caduco como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método cargar_tabla_objetivos, error metodo como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

// Carga los actividades de la propuesta
$app->get('/cargar_tabla_actividades', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);
            
            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestaPdac en el método cargar_tabla_actividades, ingresa a cargar la tabla de actividades como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
                //Consulto la propuesta solicitada
                $conditions = ['id' => $request->get('p'), 'active' => true];
                $propuesta = Propuestas::findFirst(([
                            'conditions' => 'id=:id: AND active=:active:',
                            'bind' => $conditions,
                ]));

                //Defino columnas para el orden desde la tabla html
                $columns = array(
                    0 => 'p.id',
                    1 => 'p.orden',
                    2 => 'p.actividad',
                    3 => 'po.objetivo'                    
                );
                
                $where .= " INNER JOIN Propuestasobjetivos AS po ON po.id=p.propuestaobjetivo";
                $where .= " WHERE po.propuesta = " . $propuesta->id;
                //Condiciones para la consulta

                if (!empty($request->get("search")['value'])) {
                    $where .= " AND ( UPPER(" . $columns[2] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                    $where .= " OR UPPER(" . $columns[3] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' )";
                }

                if($propuesta->estado==7)
                {
                    $check="concat('<input title=\"',p.id,'\" type=\"checkbox\" class=\"check_activar_',p.active,' activar_actividad\" />') as activar_registro";
                }
                else
                {
                    $check="concat('<input disabled title=\"',p.id,'\" type=\"checkbox\" class=\"check_activar_',p.active,' activar_actividad\" />') as activar_registro";
                }
                
                //Defino el sql del total y el array de datos
                $sqlTot = "SELECT count(*) as total FROM Propuestasactividades AS p";
                $sqlRec = "SELECT " . $columns[0] . "," . $columns[1] . "," . $columns[2] . "," . $columns[3] . ",concat('<button title=\"',p.id,'\" type=\"button\" class=\"btn btn-warning cargar_formulario_actividad\" data-toggle=\"modal\" data-target=\"#nuevo_actividad\"><span class=\"glyphicon glyphicon-edit\"></span></button>') as editar,concat('<button title=\"',p.id,'\" type=\"button\" class=\"btn btn-info cargar_actividad_cronograma\" data-toggle=\"modal\" data-target=\"#nuevo_cronograma\"><span class=\"glyphicon glyphicon-calendar\"></span></button>') as cronograma,concat('<button title=\"',p.id,'\" type=\"button\" class=\"btn btn-danger cargar_actividad_presupuesto\" data-toggle=\"modal\" data-target=\"#nuevo_presupuesto\"><span class=\"glyphicon glyphicon-usd\"></span></button>') as presupuesto,".$check." FROM Propuestasactividades AS p";

                //concarnar search sql if value exist
                if (isset($where) && $where != '') {

                    $sqlTot .= $where;
                    $sqlRec .= $where;
                }

                //Concarno el orden y el limit para el paginador
                $sqlRec .= " ORDER BY po.objetivo,p.actividad";
                
                //ejecuto el total de registros actual
                $totalRecords = $app->modelsManager->executeQuery($sqlTot)->getFirst();

                //creo el array
                $json_data = array(
                    "draw" => intval($request->get("draw")),
                    "recordsTotal" => intval($totalRecords["total"]),
                    "recordsFiltered" => intval($totalRecords["total"]),
                    "data" => $app->modelsManager->executeQuery($sqlRec)   // total data array
                );
                
                $logger->info('"token":"{token}","user":"{user}","message":"Retorna al controlador PropuestaPdac en el método cargar_tabla_actividades, retorna la tabla de actividades como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();
                
                //retorno el array en json
                echo json_encode($json_data);
            } else {
                //Registro la accion en el log de convocatorias
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método cargar_tabla_actividades, acceso denegado al cargar la tabla de actividades como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método cargar_tabla_actividades, token caduco al cargar la tabla actividades como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método cargar_tabla_actividades, error metodo al cargar a tabla de actividades como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

// Carga los cronograma de la propuesta
$app->get('/cargar_tabla_cronogramas', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestaPdac en el método editar_propuesta_actividad, ingresa a cargar tabla de cronograma como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
        
            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
                //Consulto la propuesta solicitada
                $conditions = ['id' => $request->get('p'), 'active' => true];
                $propuesta = Propuestas::findFirst(([
                            'conditions' => 'id=:id: AND active=:active:',
                            'bind' => $conditions,
                ]));
                
                //Defino columnas para el orden desde la tabla html
                $columns = array(
                    0 => 'p.id',
                    1 => 'p.fecha'
                );
                
                $where .= " INNER JOIN Propuestasactividades AS po ON po.id=p.propuestaactividad";
                $where .= " WHERE p.propuestaactividad = " . $request->get('pa');
                //Condiciones para la consulta

                if (!empty($request->get("search")['value'])) {
                    $where .= " AND ( UPPER(" . $columns[2] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                    $where .= " OR UPPER(" . $columns[3] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' )";
                }
                
                if($propuesta->estado==7)
                {
                    $check="concat('<input title=\"',p.id,'\" type=\"checkbox\" class=\"check_activar_',p.active,' activar_cronograma\" />') as activar_registro";
                }
                else
                {
                    $check="concat('<input disabled title=\"',p.id,'\" type=\"checkbox\" class=\"check_activar_',p.active,' activar_cronograma\" />') as activar_registro";
                }

                //Defino el sql del total y el array de datos
                $sqlTot = "SELECT count(*) as total FROM Propuestascronogramas AS p";
                $sqlRec = "SELECT " . $columns[0] . "," . $columns[1] . ",concat('<button title=\"',p.id,'\" type=\"button\" class=\"btn btn-warning cargar_formulario_cronograma\"><span class=\"glyphicon glyphicon-edit\"></span></button>') as editar, ".$check." FROM Propuestascronogramas AS p";

                //concarnar search sql if value exist
                if (isset($where) && $where != '') {

                    $sqlTot .= $where;
                    $sqlRec .= $where;
                }

                //Concarno el orden y el limit para el paginador
                $sqlRec .= " ORDER BY p.fecha";                                
                
                //ejecuto el total de registros actual
                $totalRecords = $app->modelsManager->executeQuery($sqlTot)->getFirst();

                //creo el array
                $json_data = array(
                    "draw" => intval($request->get("draw")),
                    "recordsTotal" => intval($totalRecords["total"]),
                    "recordsFiltered" => intval($totalRecords["total"]),
                    "data" => $app->modelsManager->executeQuery($sqlRec)   // total data array
                );
                //retorno el array en json
                echo json_encode($json_data);
            } else {
                //Registro la accion en el log de convocatorias
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método editar_propuesta_actividad, acceso denegado como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current['username'], 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método editar_propuesta_actividad, token caduco como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método editar_propuesta_actividad, error metodo como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

// Carga los presupuestos de la propuesta
$app->get('/cargar_tabla_presupuestos', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestaPdac en el método cargar_tabla_presupuestos, ingresa a cargar la tabla de presupuesto como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current['username'], 'token' => $request->get('token')]);
            
            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
                //Consulto la propuesta solicitada
                $conditions = ['id' => $request->get('p'), 'active' => true];
                $propuesta = Propuestas::findFirst(([
                            'conditions' => 'id=:id: AND active=:active:',
                            'bind' => $conditions,
                ]));
                
                //Defino columnas para el orden desde la tabla html
                $columns = array(
                    0 => 'p.id',
                    1 => 'p.propuestaactividad',
                    2 => 'p.insumo',
                    3 => 'p.cantidad',
                    4 => 'p.unidadmedida',
                    5 => 'p.valorunitario',
                    6 => 'p.valortotal',
                    7 => 'p.aportesolicitado',
                    8 => 'p.aportecofinanciado',
                    9 => 'p.aportepropio',
                    10 => 'p.active'
                );
                
                $where .= " INNER JOIN Propuestasactividades AS po ON po.id=p.propuestaactividad";
                $where .= " WHERE p.propuestaactividad = " . $request->get('pa');
                //Condiciones para la consulta

                if (!empty($request->get("search")['value'])) {
                    $where .= " AND ( UPPER(" . $columns[2] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' )";
                }

                if($propuesta->estado==7)
                {
                    $check="concat('<input title=\"',p.id,'\" type=\"checkbox\" class=\"check_activar_',p.active,' activar_presupuesto\" />') as activar_registro";
                }
                else
                {
                    $check="concat('<input disabled title=\"',p.id,'\" type=\"checkbox\" class=\"check_activar_',p.active,' activar_presupuesto\" />') as activar_registro";
                }
                
                //Defino el sql del total y el array de datos
                $sqlTot = "SELECT count(*) as total FROM Propuestaspresupuestos AS p";
                $sqlRec = "SELECT " . $columns[0] . "," . $columns[1] . "," . $columns[2] . "," . $columns[3] . "," . $columns[4] . "," . $columns[5] . "," . $columns[6] . "," . $columns[7] . "," . $columns[8] . "," . $columns[9] . ",concat('<button title=\"',p.id,'\" type=\"button\" class=\"btn btn-warning cargar_formulario_presupuesto\"><span class=\"glyphicon glyphicon-edit\"></span></button>') as editar , ".$check." FROM Propuestaspresupuestos AS p";

                //concarnar search sql if value exist
                if (isset($where) && $where != '') {

                    $sqlTot .= $where;
                    $sqlRec .= $where;
                }

                //Concarno el orden y el limit para el paginador
                $sqlRec .= " ORDER BY p.insumo";                                                
                
                //ejecuto el total de registros actual
                $totalRecords = $app->modelsManager->executeQuery($sqlTot)->getFirst();

                //creo el array
                $json_data = array(
                    "draw" => intval($request->get("draw")),
                    "recordsTotal" => intval($totalRecords["total"]),
                    "recordsFiltered" => intval($totalRecords["total"]),
                    "data" => $app->modelsManager->executeQuery($sqlRec)   // total data array
                );
                //retorno el array en json
                echo json_encode($json_data);
            } else {
                //Registro la accion en el log de convocatorias
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método cargar_tabla_presupuestos, acceso denegado como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current['username'], 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método cargar_tabla_presupuestos, token caduco como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método cargar_tabla_presupuestos, error metodo como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

//Busca el registro
$app->get('/consultar_objetivo', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            //Si existe consulto la convocatoria
            if ($request->get('id')) {
                $propuestaobjetivo = Propuestasobjetivos::findFirst($request->get('id'));
            } else {
                $propuestaobjetivo = new Propuestasobjetivos();
            }

            //Retorno el array
            echo json_encode($propuestaobjetivo);
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
$app->get('/consultar_cronograma', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            //Si existe consulto la convocatoria
            if ($request->get('id')) {
                $propuestaobjetivo = Propuestascronogramas::findFirst($request->get('id'));
            } else {
                $propuestaobjetivo = new Propuestascronogramas();
            }

            //Retorno el array
            echo json_encode($propuestaobjetivo);
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
$app->get('/consultar_presupuesto', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            //Si existe consulto la convocatoria
            if ($request->get('id')) {
                $propuestaobjetivo = Propuestaspresupuestos::findFirst($request->get('id'));
            } else {
                $propuestaobjetivo = new Propuestaspresupuestos();
            }

            //Retorno el array
            echo json_encode($propuestaobjetivo);
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        echo "error_metodo";
    }
}
);

$app->get('/consultar_actividad', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            //Si existe consulto la convocatoria
            if ($request->get('id')) {
                $propuestaobjetivo = Propuestasactividades::findFirst($request->get('id'));
            } else {
                $propuestaobjetivo = new Propuestasactividades();
            }

            //Retorno el array
            echo json_encode($propuestaobjetivo);
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        echo "error_metodo";
    }
}
);

// Eliminar registro
$app->delete('/eliminar_objetivo_especifico/{id:[0-9]+}', function ($id) use ($app, $config,$logger) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                // Consultar el usuario que se esta editando
                $user = Propuestasobjetivos::findFirst(json_decode($id));
                if($request->getPut('active')=='false')
                {
                    $user->active = FALSE;
                }
                if($request->getPut('active')=='true')
                {
                    $user->active = true;
                }
                
                if ($user->save($user) === false) {
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método eliminar_objetivo_especifico, error al activar o inactivar el objetivo espefico (' . $id . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "error";
                } else {
                    $logger->info('"token":"{token}","user":"{user}","message":"Retorno en el controlador PropuestaPdac en el método eliminar_objetivo_especifico, edito al activar o inactivar el objetivo espefico (' . $id . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);                    
                    $logger->close();
                    echo "ok";
                }
            } else {
                echo "acceso_denegado";
            }

            exit;
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo";
    }
});

// Eliminar registro
$app->delete('/eliminar_actividad/{id:[0-9]+}', function ($id) use ($app, $config,$logger) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                // Consultar el usuario que se esta editando
                $user = Propuestasactividades::findFirst(json_decode($id));
                if($request->getPut('active')=='false')
                {
                    $user->active = FALSE;
                }
                if($request->getPut('active')=='true')
                {
                    $user->active = true;
                }
                
                if ($user->save($user) === false) {
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método eliminar_actividad, error al activar o inactivar la actividad (' . $id . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "error";
                } else {
                    $logger->info('"token":"{token}","user":"{user}","message":"Retorno en el controlador PropuestaPdac en el método eliminar_actividad, edito al activar o inactivar la actividad (' . $id . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);                    
                    $logger->close();
                    echo "ok";
                }
            } else {
                echo "acceso_denegado";
            }

            exit;
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo";
    }
});

// Eliminar registro
$app->delete('/eliminar_cronograma/{id:[0-9]+}', function ($id) use ($app, $config,$logger) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                // Consultar el usuario que se esta editando
                $user = Propuestascronogramas::findFirst(json_decode($id));
                if($request->getPut('active')=='false')
                {
                    $user->active = FALSE;
                }
                if($request->getPut('active')=='true')
                {
                    $user->active = true;
                }
                
                if ($user->save($user) === false) {
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método eliminar_cronograma, error al activar o inactivar el cronograma (' . $id . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "error";
                } else {
                    $logger->info('"token":"{token}","user":"{user}","message":"Retorno en el controlador PropuestaPdac en el método eliminar_cronograma, edito al activar o inactivar el cronograma (' . $id . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);                    
                    $logger->close();
                    echo "ok";
                }
            } else {
                echo "acceso_denegado";
            }

            exit;
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo";
    }
});

// Eliminar registro
$app->delete('/eliminar_presupuesto/{id:[0-9]+}', function ($id) use ($app, $config,$logger) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                // Consultar el usuario que se esta editando
                $user = Propuestaspresupuestos::findFirst(json_decode($id));
                if($request->getPut('active')=='false')
                {
                    $user->active = FALSE;
                }
                if($request->getPut('active')=='true')
                {
                    $user->active = true;
                }
                
                if ($user->save($user) === false) {
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaPdac en el método eliminar_presupuesto, error al activar o inactivar el presupuesto (' . $id . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "error";
                } else {
                    $logger->info('"token":"{token}","user":"{user}","message":"Retorno en el controlador PropuestaPdac en el método eliminar_presupuesto, edito al activar o inactivar el presupuesto (' . $id . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);                    
                    $logger->close();
                    echo "ok";
                }
            } else {
                echo "acceso_denegado";
            }

            exit;
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo";
    }
});


try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage();
}
?>