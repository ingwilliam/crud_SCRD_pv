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


$app->post('/general_propuestas_inscritas', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    try {
        $array = array();

        $where = "";
        $where_sexo = "";
        if ($request->getPost('anio') != "") {
            $where = $where . " AND vc.anio = " . $request->getPost('anio');
            $where_sexo = $where_sexo . " AND vc.anio = " . $request->getPost('anio');
        }

        if ($request->getPost('programa') != "") {
            $where = $where . " AND vc.programa = " . $request->getPost('programa');
            $where_sexo = $where_sexo . " AND vc.id_programa = " . $request->getPost('programa');
        }

        if ($request->getPost('entidad') != "") {
            $where = $where . " AND vc.entidad = " . $request->getPost('entidad');
            $where_sexo = $where_sexo . " AND vc.id_entidad = " . $request->getPost('entidad');
        }

        if ($request->getPost('sexo') != "") {
            $where = $where . " AND par.sexo = " . $request->getPost('sexo');
            $where_sexo = $where_sexo . " AND vc.id_sexo = " . $request->getPost('sexo');
        }

        if ($request->getPost('area') != "") {
            $where = $where . " AND vc.area = " . $request->getPost('area');
            $where_sexo = $where_sexo . " AND vc.id_area = " . $request->getPost('area');
        }

        if ($request->getPost('linea_estrategica') != "") {
            $where = $where . " AND vc.linea_estrategica = " . $request->getPost('linea_estrategica');
            $where_sexo = $where_sexo . " AND vc.id_linea_estrategica = " . $request->getPost('linea_estrategica');
        }

        if ($request->getPost('enfoque') != "") {
            $where = $where . " AND vc.enfoque = " . $request->getPost('enfoque');
            $where_sexo = $where_sexo . " AND vc.id_enfoque = " . $request->getPost('enfoque');
        }

        if ($request->getPost('localidad') != "") {
            $where = $where . " AND p.localidad = " . $request->getPost('localidad');
            $where_sexo = $where_sexo . " AND vc.id_localidad_residencia = " . $request->getPost('localidad');
        }

        if ($request->getPost('tipoparticipante') != "") {
            $where = $where . " AND up.perfil = " . $request->getPost('tipoparticipante');
            $where_sexo = $where_sexo . " AND vc.id_perfil = " . $request->getPost('tipoparticipante');
        }
        
        if ($request->getPost('convocatoria') != "") {
            $where = $where . " AND p.convocatoria = " . $request->getPost('convocatoria');
            $where_sexo = $where_sexo . " AND vc.id_convocatoria = " . $request->getPost('convocatoria');
        }

        //Propuestas por anio
        $sql_propuestas = "
            SELECT 
                    vc.anio,
                    COUNT(p.id) AS total_propuestas
            FROM 
            Propuestas AS p
            INNER JOIN Viewconvocatorias AS vc ON vc.id_categoria=p.convocatoria
            INNER JOIN Participantes AS par ON par.id=p.participante
            INNER JOIN Usuariosperfiles AS up ON up.id=par.usuario_perfil
            WHERE 
            p.estado NOT IN (7,20) " . $where . "
            GROUP BY 1
            ORDER BY 2
            ";

        $propuestas_anio = $app->modelsManager->executeQuery($sql_propuestas);
        $array_value = array();
        $array_label = array();
        foreach ($propuestas_anio AS $clave => $valor) {
            $array_value[] = $valor->total_propuestas;
            $array_label[] = $valor->anio;
        }

        $array["propuestas_anio"]["value"] = $array_value;
        $array["propuestas_anio"]["label"] = $array_label;

        //Propuestas por programa
        $sql_propuestas = "
            SELECT 
                    pro.nombre AS label,
                    COUNT(p.id) AS total_propuestas
            FROM 
            Propuestas AS p
            INNER JOIN Viewconvocatorias AS vc ON vc.id_categoria=p.convocatoria
            INNER JOIN Programas AS pro ON pro.id=vc.programa
            INNER JOIN Participantes AS par ON par.id=p.participante
            INNER JOIN Usuariosperfiles AS up ON up.id=par.usuario_perfil
            WHERE 
            p.estado NOT IN (7,20) " . $where . "
            GROUP BY 1
            ORDER BY 2
            ";

        $propuestas_programa = $app->modelsManager->executeQuery($sql_propuestas);
        $array_value = array();
        $array_label = array();
        foreach ($propuestas_programa AS $clave => $valor) {
            $array_value[] = $valor->total_propuestas;
            $array_label[] = $valor->label;
        }

        $array["propuestas_programa"]["value"] = $array_value;
        $array["propuestas_programa"]["label"] = $array_label;

        //Propuestas por entidad
        $sql_propuestas = "
            SELECT 
                    vc.nombre_entidad AS label,
                    COUNT(p.id) AS total_propuestas
            FROM 
            Propuestas AS p
            INNER JOIN Viewconvocatorias AS vc ON vc.id_categoria=p.convocatoria            
            INNER JOIN Participantes AS par ON par.id=p.participante
            INNER JOIN Usuariosperfiles AS up ON up.id=par.usuario_perfil
            WHERE 
            p.estado NOT IN (7,20) " . $where . "
            GROUP BY 1
            ORDER BY 2
            ";

        $propuestas_entidad = $app->modelsManager->executeQuery($sql_propuestas);
        $array_value = array();
        $array_label = array();
        foreach ($propuestas_entidad AS $clave => $valor) {
            $array_value[] = $valor->total_propuestas;
            $array_label[] = $valor->label;
        }

        $array["propuestas_entidad"]["value"] = $array_value;
        $array["propuestas_entidad"]["label"] = $array_label;

        //Propuestas por sexo
        $sql_propuestas = "
            SELECT 
                    vc.sexo AS label,
                    COUNT(vc.id_sexo) AS total_propuestas
            FROM 
            Viewparticipantes AS vc
            WHERE 
            vc.sexo IS NOT NULL " . $where_sexo . "
            GROUP BY 1
            ORDER BY 2
            ";

        $propuestas_sexos = $app->modelsManager->executeQuery($sql_propuestas);
        $array_value = array();
        $i = 0;
        foreach ($propuestas_sexos AS $clave => $valor) {
            $array_value[$i][0] = $valor->label;
            $array_value[$i][1] = $valor->total_propuestas;
            $i++;
        }
        $array["propuestas_sexo"] = $array_value;

        //Propuestas por area
        $sql_propuestas = "
            SELECT 
                    ar.nombre AS label,
                    COUNT(p.id) AS total_propuestas
            FROM 
            Propuestas AS p
            INNER JOIN Viewconvocatorias AS vc ON vc.id_categoria=p.convocatoria
            INNER JOIN Areas AS ar ON ar.id=vc.area
            INNER JOIN Participantes AS par ON par.id=p.participante
            INNER JOIN Usuariosperfiles AS up ON up.id=par.usuario_perfil
            WHERE 
            p.estado NOT IN (7,20) " . $where . "
            GROUP BY 1
            ORDER BY 2
            ";

        $propuestas_area = $app->modelsManager->executeQuery($sql_propuestas);
        $array_value = array();
        $array_label = array();
        foreach ($propuestas_area AS $clave => $valor) {
            $array_value[] = $valor->total_propuestas;
            $array_label[] = $valor->label;
        }

        $array["propuestas_area"]["value"] = $array_value;
        $array["propuestas_area"]["label"] = $array_label;

        //Propuestas por linea
        $sql_propuestas = "
            SELECT 
                    li.nombre AS label,
                    COUNT(p.id) AS total_propuestas
            FROM 
            Propuestas AS p
            INNER JOIN Viewconvocatorias AS vc ON vc.id_categoria=p.convocatoria
            INNER JOIN Lineasestrategicas AS li ON li.id=vc.linea_estrategica
            INNER JOIN Participantes AS par ON par.id=p.participante
            INNER JOIN Usuariosperfiles AS up ON up.id=par.usuario_perfil
            WHERE 
            p.estado NOT IN (7,20) " . $where . "
            GROUP BY 1
            ORDER BY 2
            ";

        $propuestas_linea = $app->modelsManager->executeQuery($sql_propuestas);
        $array_value = array();
        $array_label = array();
        foreach ($propuestas_linea AS $clave => $valor) {
            $array_value[] = $valor->total_propuestas;
            $array_label[] = $valor->label;
        }

        $array["propuestas_linea"]["value"] = $array_value;
        $array["propuestas_linea"]["label"] = $array_label;

        //Propuestas por enfoque
        $sql_propuestas = "
            SELECT 
                    en.nombre AS label,
                    COUNT(p.id) AS total_propuestas
            FROM 
            Propuestas AS p
            INNER JOIN Viewconvocatorias AS vc ON vc.id_categoria=p.convocatoria
            INNER JOIN Enfoques AS en ON en.id=vc.enfoque
            INNER JOIN Participantes AS par ON par.id=p.participante
            INNER JOIN Usuariosperfiles AS up ON up.id=par.usuario_perfil
            WHERE 
            p.estado NOT IN (7,20) " . $where . "
            GROUP BY 1
            ORDER BY 2
            ";

        $propuestas_enfoque = $app->modelsManager->executeQuery($sql_propuestas);
        $array_value = array();
        $array_label = array();
        foreach ($propuestas_enfoque AS $clave => $valor) {
            $array_value[] = $valor->total_propuestas;
            $array_label[] = $valor->label;
        }

        $array["propuestas_enfoque"]["value"] = $array_value;
        $array["propuestas_enfoque"]["label"] = $array_label;

        //Propuestas por localidad
        $sql_propuestas = "
            SELECT 
                    lo.nombre AS label,
                    COUNT(p.id) AS total_propuestas
            FROM 
            Propuestas AS p
            INNER JOIN Localidades AS lo ON lo.id=p.localidad
            INNER JOIN Viewconvocatorias AS vc ON vc.id_categoria=p.convocatoria
            INNER JOIN Participantes AS par ON par.id=p.participante
            INNER JOIN Usuariosperfiles AS up ON up.id=par.usuario_perfil
            WHERE 
            p.estado NOT IN (7,20) " . $where . "
            GROUP BY 1
            ORDER BY 2
            ";

        $propuestas_localidad = $app->modelsManager->executeQuery($sql_propuestas);
        $array_value = array();
        $array_label = array();
        foreach ($propuestas_localidad AS $clave => $valor) {
            $array_value[] = $valor->total_propuestas;
            $array_label[] = $valor->label;
        }

        $array["propuestas_localidad"]["value"] = $array_value;
        $array["propuestas_localidad"]["label"] = $array_label;

        //Propuestas por tipo participante
        $sql_propuestas = "
            SELECT 
                    per.nombre AS label,
                    COUNT(p.id) AS total_propuestas
            FROM 
            Propuestas AS p
            INNER JOIN Participantes AS par ON par.id=p.participante
            INNER JOIN Usuariosperfiles AS up ON up.id=par.usuario_perfil
            INNER JOIN Perfiles AS per ON per.id=up.perfil
            INNER JOIN Viewconvocatorias AS vc ON vc.id_categoria=p.convocatoria
            WHERE 
            p.estado NOT IN (7,20) " . $where . "
            GROUP BY 1
            ORDER BY 2
            ";

        $propuestas_participante = $app->modelsManager->executeQuery($sql_propuestas);
        $array_value = array();
        $array_label = array();
        foreach ($propuestas_participante AS $clave => $valor) {
            $array_value[] = $valor->total_propuestas;
            $array_label[] = $valor->label;
        }

        $array["propuestas_participante"]["value"] = $array_value;
        $array["propuestas_participante"]["label"] = $array_label;

        $array["fecha_corte"] = date("Y-m-d H:i:s");

        echo json_encode($array);
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo select_convocatorias para cargar las convocatorias con el año (' . $request->get('anio') . ') y la entidad (' . $request->get('entidad') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->post('/general_anio', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    try {
        $array = array();

        $where = "vwc.anio=".date("Y");
        if ($request->getPost('anio') != "") {
            $where = "vwc.anio=".$request->getPost('anio');            
        }

        $array_entidades =  Entidades::find("active = true");
        $in_entidades = "";
        foreach ($array_entidades as $entidad) {
            $in_entidades = $in_entidades.$entidad->id.",";
        }
        $in_entidades = trim($in_entidades,",");
        if ($request->getPost('entidad') != "") {
            $in_entidades = $request->getPost('entidad');
        }
        //Convocatorias ofertadas por anio
        //Entidad        
        $sql_propuestas = "
            SELECT 
                vwc.nombre_entidad AS label,
                count(vwc.id) AS total_propuestas
            FROM 
                Viewconvocatoriaspublicas AS vwc
            WHERE
                ".$where." AND vwc.estado IN (5,6,32,43,45) AND vwc.entidad IN (".$in_entidades.")
            GROUP BY 1
            ORDER BY 2 DESC
            ";
        
        $convocatorias_anio = $app->modelsManager->executeQuery($sql_propuestas);
        $array_value = array();
        $i = 0;
        foreach ($convocatorias_anio AS $clave => $valor) {
            $array_value[$i][0] = $valor->label;
            $array_value[$i][1] = $valor->total_propuestas;
            $i++;
        }
        $array["estados_convocatoria_anio"] = $array_value;
        $array["tabla_estados_convocatoria_anio"] = $convocatorias_anio;
        
        //Convocatorias estado        
        //Propuestas Inscritas
        $sql_propuestas = "
            SELECT 
            es.nombre AS label,
            count(vwp.id_propuesta) AS total_propuestas
        FROM 
            Viewpropuestas AS vwp
        INNER JOIN Viewconvocatorias AS vwc ON vwc.id_categoria=vwp.id_convocatoria_propuesta_inscrita
        INNER JOIN Estados AS es ON es.id=vwc.estado 
        WHERE
            ".$where." AND vwc.estado IN (5,6,32,43,45) AND vwp.id_estado NOT IN (7,20) AND vwp.id_entidad IN (".$in_entidades.")
        GROUP BY 1
        ORDER BY 2 ASC
            ";

        $convocatorias_anio = $app->modelsManager->executeQuery($sql_propuestas);
        
        $array_value = array();
        $array_label = array();
        foreach ($convocatorias_anio AS $clave => $valor) {
            $array_value[] = $valor->total_propuestas;
            $array_label[] = $valor->label;
        }

        $array["estados_convocatoria_propuestas_anio"]["value"] = $array_value;
        $array["estados_convocatoria_propuestas_anio"]["label"] = $array_label;
        $array["tabla_convocatoria_propuestas_anio"] = $convocatorias_anio;
        
        //Propuestas por entidad
        $sql_propuestas = "
            SELECT 
                    vwc.nombre_entidad AS label,
                    COUNT(vwc.id_propuesta) AS total_propuestas
            FROM 
                    Viewpropuestas AS vwc 
            INNER JOIN Viewconvocatorias AS vwp ON vwp.id_categoria=vwc.id_convocatoria_propuesta_inscrita               
            WHERE 
                    ".$where." AND vwp.estado IN (5,6,32,43,45) AND vwc.id_estado NOT IN (7,20) AND vwc.id_entidad IN (".$in_entidades.")
            GROUP BY 1
            ORDER BY 2
            ";

        $propuestas_entidad = $app->modelsManager->executeQuery($sql_propuestas);
        $array_value = array();
        $array_label = array();
        foreach ($propuestas_entidad AS $clave => $valor) {
            $array_value[] = $valor->total_propuestas;
            $array_label[] = $valor->label;
        }

        $array["propuestas_entidad_anio"]["value"] = $array_value;
        $array["propuestas_entidad_anio"]["label"] = $array_label;
        $array["tabla_propuestas_entidad_anio"] = $propuestas_entidad;
        
        //Participante por rango etareo
        $sql_propuestas = "
            SELECT
                vwc.rango AS label,
                SUM(vwc.count) AS total_propuestas
            FROM Viewrangosetareos AS vwc
            WHERE ".$where." AND vwc.entidad IN (".$in_entidades.")
            GROUP BY 1
            ORDER BY 2            
            ";

        $convocatorias_anio = $app->modelsManager->executeQuery($sql_propuestas);
        
        $array_value = array();
        $array_label = array();
        foreach ($convocatorias_anio AS $clave => $valor) {
            $array_value[] = $valor->total_propuestas;
            $array_label[] = $valor->label;
        }

        $array["propuestas_rango_etareo_anio"]["value"] = $array_value;
        $array["propuestas_rango_etareo_anio"]["label"] = $array_label;
        $array["tabla_propuestas_rango_etareo_anio"] = $convocatorias_anio;                
        
        //Propuestas por area
        $sql_propuestas = "
            SELECT 
                    vwc.area AS label,
                    COUNT(vwc.id_propuesta) AS total_propuestas
            FROM 
                    Viewpropuestas AS vwc 
            WHERE 
                    ".$where." and vwc.id_estado NOT IN (7,20) AND vwc.id_entidad IN (".$in_entidades.")
            GROUP BY 1
            ORDER BY 2
            ";

        $propuestas_area = $app->modelsManager->executeQuery($sql_propuestas);
        $array_value = array();
        $array_label = array();
        foreach ($propuestas_area AS $clave => $valor) {
            $array_value[] = $valor->total_propuestas;
            $array_label[] = $valor->label;
        }

        $array["propuestas_area_anio"]["value"] = $array_value;
        $array["propuestas_area_anio"]["label"] = $array_label;
        
        $array["table_propuestas_area_anio"] = $propuestas_area;
        
        //Propuestas por lineaestrategica
        $sql_propuestas = "
            SELECT 
                    vwc.lineaestrategica AS label,
                    COUNT(vwc.id_propuesta) AS total_propuestas
            FROM 
                    Viewpropuestas AS vwc 
            WHERE 
                    ".$where." and vwc.id_estado NOT IN (7,20) AND vwc.id_entidad IN (".$in_entidades.")
            GROUP BY 1
            ORDER BY 2
            ";

        $propuestas_lineaestrategica = $app->modelsManager->executeQuery($sql_propuestas);
        $array_value = array();
        $array_label = array();
        foreach ($propuestas_lineaestrategica AS $clave => $valor) {
            $array_value[] = $valor->total_propuestas;
            $array_label[] = $valor->label;
        }

        $array["propuestas_lineaestrategica_anio"]["value"] = $array_value;
        $array["propuestas_lineaestrategica_anio"]["label"] = $array_label;
        $array["table_propuestas_lineaestrategica_anio"] = $propuestas_lineaestrategica;
        
        //Propuestas por enfoque
        $sql_propuestas = "
            SELECT 
                    vwc.enfoque AS label,
                    COUNT(vwc.id_propuesta) AS total_propuestas
            FROM 
                    Viewpropuestas AS vwc 
            WHERE 
                    ".$where." and vwc.id_estado NOT IN (7,20) AND vwc.id_entidad IN (".$in_entidades.")
            GROUP BY 1
            ORDER BY 2
            ";

        $propuestas_enfoque = $app->modelsManager->executeQuery($sql_propuestas);
        $array_value = array();
        $array_label = array();
        foreach ($propuestas_enfoque AS $clave => $valor) {
            $array_value[] = $valor->total_propuestas;
            $array_label[] = $valor->label;
        }

        $array["propuestas_enfoque_anio"]["value"] = $array_value;
        $array["propuestas_enfoque_anio"]["label"] = $array_label;
        $array["table_propuestas_enfoque_anio"] = $propuestas_enfoque;
        
        //Propuestas por participante
        $sql_propuestas = "
            SELECT 
                    per.nombre AS label,
                    COUNT(vwc.id_propuesta) as total_propuestas
            FROM 
                    Viewpropuestas AS vwc
            INNER JOIN Propuestas AS p ON p.id=vwc.id_propuesta
            INNER JOIN Participantes AS par ON par.id=p.participante
            INNER JOIN Usuariosperfiles AS up ON up.id=par.usuario_perfil
            INNER JOIN Perfiles AS per ON per.id=up.perfil	
            WHERE 
                    ".$where." and vwc.id_estado NOT IN (7,20) AND vwc.id_entidad IN (".$in_entidades.")
            GROUP BY 1
            ORDER BY 2
            ";

        $propuestas_tipoparticipante = $app->modelsManager->executeQuery($sql_propuestas);
        $array_value = array();
        $array_label = array();
        foreach ($propuestas_tipoparticipante AS $clave => $valor) {
            $array_value[] = $valor->total_propuestas;
            $array_label[] = $valor->label;
        }

        $array["propuestas_tipoparticipante_anio"]["value"] = $array_value;
        $array["propuestas_tipoparticipante_anio"]["label"] = $array_label;
        $array["table_propuestas_tipoparticipante_anio"] = $propuestas_tipoparticipante;
        
        //Propuestas por localidad de ejecucion
        $sql_propuestas = "
            select 
                    vwc.localidad_ejecucion_propuesta as label,
                    COUNT(vwc.id_convocatoria) as total_propuestas
            from 
                    Viewpropuestas as vwc 
            where 
                    ".$where." and vwc.id_estado NOT IN (7,20)  AND vwc.id_entidad IN (".$in_entidades.")
            group by 1
            ORDER BY 2
            ";

        $propuestas_localidadeje = $app->modelsManager->executeQuery($sql_propuestas);
        $array_value = array();
        $array_label = array();
        foreach ($propuestas_localidadeje AS $clave => $valor) {
            $array_value[] = $valor->total_propuestas;
            $array_label[] = $valor->label;
        }

        $array["propuestas_localidadeje_anio"]["value"] = $array_value;
        $array["propuestas_localidadeje_anio"]["label"] = $array_label;
        $array["table_propuestas_localidadeje_anio"] = $propuestas_localidadeje;
                
        //Propuestas por localidad de ejecucion
        $sql_propuestas = "
            select 
                    vwc.localidad_ejecucion_propuesta as label,
                    sum(vwc.monto_asignado) as total_propuestas
            from 
                    Viewpropuestas as vwc 
            where 
                    ".$where." and vwc.estado_propuesta NOT IN ('Guardada - No Inscrita','Anulada') and vwc.localidad_ejecucion_propuesta is not null  AND vwc.id_entidad IN (".$in_entidades.")
            group by 1
            order by 2 ASC
            ";
        
        $propuestas_localidadeje = $app->modelsManager->executeQuery($sql_propuestas);
        $array_value = array();
        $array_label = array();
        foreach ($propuestas_localidadeje AS $clave => $valor) {
            $array_value[] = $valor->total_propuestas;
            $array_label[] = $valor->label;
        }

        $array["valor_localidadeje_anio"]["value"] = $array_value;
        $array["valor_localidadeje_anio"]["label"] = $array_label;

        $array["table_valor_localidadeje_anio"] = $propuestas_localidadeje;
        
        //Propuestas por entidad de ejecucion
        $sql_propuestas = "
            select 
                    vwc.nombre_entidad as label,
                    sum(vwc.monto_asignado) as total_propuestas
            from 
                    Viewpropuestas as vwc 
            where 
                    ".$where." and vwc.estado_propuesta NOT IN ('Guardada - No Inscrita','Anulada') AND vwc.id_entidad IN (".$in_entidades.")
            group by 1
            order by 2 ASC
            ";
        
        $propuestas_entidadeje = $app->modelsManager->executeQuery($sql_propuestas);
        $array_value = array();
        $array_label = array();
        foreach ($propuestas_entidadeje AS $clave => $valor) {
            $array_value[] = $valor->total_propuestas;
            $array_label[] = $valor->label;
        }

        $array["valor_entidadeje_anio"]["value"] = $array_value;
        $array["valor_entidadeje_anio"]["label"] = $array_label;

        $array["table_valor_entidadeje_anio"] = $propuestas_entidadeje;
        
        //Ofertado por entidad
        $sql_propuestas = "
            select 
                    vwc.nombre_entidad as label,
                    vwc.sum as total_propuestas
            from 
                    Viewofertado as vwc 
            where 
                    ".$where." AND vwc.id_entidad IN (".$in_entidades.")            
            order by 2 ASC
            ";
        
        $ofertado_entidad = $app->modelsManager->executeQuery($sql_propuestas);
        $array_value = array();
        $array_label = array();
        foreach ($ofertado_entidad AS $clave => $valor) {
            $array_value[] = $valor->total_propuestas;
            $array_label[] = $valor->label;
        }

        $array["valor_ofertado_entidad"]["value"] = $array_value;
        $array["valor_ofertado_entidad"]["label"] = $array_label;

        $array["table_valor_ofertado_entidad"] = $ofertado_entidad;
        
        $array["fecha_corte"] = date("Y-m-d H:i:s");

        echo json_encode($array);
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo select_convocatorias para cargar las convocatorias con el año (' . $request->get('anio') . ') y la entidad (' . $request->get('entidad') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->get('/select_convocatorias', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();

    try {


        $array_convocatorias = Viewconvocatorias::find("anio='" . $request->get('anio') . "' AND entidad=" . $request->get('entidad') . " AND estado > 4 AND active=TRUE ORDER BY convocatoria,categoria");

        $array_interno = array();
        $i=0;
        foreach ($array_convocatorias as $convocatoria) {
            $array_interno[$i]["id"] = $convocatoria->id_categoria;
            $nombre = $convocatoria->convocatoria;
            if($convocatoria->categoria!="")
            {
                $nombre = $convocatoria->convocatoria." - ".$convocatoria->categoria;
            }
            
            $array_interno[$i]["nombre"] = $nombre;            
            $i++;
        }

        echo json_encode($array_interno);
    } catch (Exception $ex) {
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