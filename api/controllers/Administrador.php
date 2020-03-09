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

// Recupera todos los registros
$app->post('/menu', function () use ($app,$config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            //Extraemos el usuario del token
            $user_current = json_decode($token_actual->user_current, true);

            //Consultar todos los permiso del panel de seguridad
            $phql = "SELECT mpp.* FROM Moduloperfilpermisos AS mpp "
                    . "INNER JOIN Modulos AS m ON m.id=mpp.modulo "
                    . "WHERE m.nombre='Panel de Seguridad' AND mpp.perfil IN (SELECT up.perfil FROM Usuariosperfiles AS up WHERE up.usuario=".$user_current["id"].")";

            $permisos_panel_de_seguridad = $app->modelsManager->executeQuery($phql);

            //Consultar todos los permiso de la administración
            $phql = "SELECT mpp.* FROM Moduloperfilpermisos AS mpp "
                    . "INNER JOIN Modulos AS m ON m.id=mpp.modulo "
                    . "WHERE m.nombre='Administración' AND mpp.perfil IN (SELECT up.perfil FROM Usuariosperfiles AS up WHERE up.usuario=".$user_current["id"].")";

            $permisos_administracion = $app->modelsManager->executeQuery($phql);

            //Consultar todos los permiso de la convocatorias
            $phql = "SELECT mpp.* FROM Moduloperfilpermisos AS mpp "
                    . "INNER JOIN Modulos AS m ON m.id=mpp.modulo "
                    . "WHERE m.nombre='Convocatorias' AND mpp.perfil IN (SELECT up.perfil FROM Usuariosperfiles AS up WHERE up.usuario=".$user_current["id"].")";

            $permisos_convocatorias = $app->modelsManager->executeQuery($phql);

            //Consultar todos los permiso de la propuestas
            $phql = "SELECT mpp.* FROM Moduloperfilpermisos AS mpp "
                    . "INNER JOIN Modulos AS m ON m.id=mpp.modulo "
                    . "WHERE m.nombre='Búsqueda de propuestas' AND mpp.perfil IN (SELECT up.perfil FROM Usuariosperfiles AS up WHERE up.usuario=".$user_current["id"].")";

            $permisos_propuestas = $app->modelsManager->executeQuery($phql);

            //Consultar todos los permiso del menu participante
            $phql = "SELECT mpp.* FROM Moduloperfilpermisos AS mpp "
                    . "INNER JOIN Modulos AS m ON m.id=mpp.modulo "
                    . "WHERE m.nombre='Menu Participante' AND mpp.perfil IN (SELECT up.perfil FROM Usuariosperfiles AS up WHERE up.usuario=".$user_current["id"].")";

            $permisos_menu_participante = $app->modelsManager->executeQuery($phql);

            //Cesar Britto
            //Consultar todos los permiso del menu jurados
            $phql = "SELECT mpp.* FROM Moduloperfilpermisos AS mpp "
                    . "INNER JOIN Modulos AS m ON m.id=mpp.modulo "
                    . "WHERE m.nombre='Jurados' AND mpp.perfil IN (SELECT up.perfil FROM Usuariosperfiles AS up WHERE up.usuario=".$user_current["id"].")";

            $permisos_jurados = $app->modelsManager->executeQuery($phql);


            //Cesar Britto, 2020-01-17
            //Consultar todos los permiso del menu jurados
            $phql = "SELECT mpp.* FROM Moduloperfilpermisos AS mpp "
                . " INNER JOIN Modulos AS m ON m.id=mpp.modulo "
                . " WHERE m.nombre='Evaluación de propuestas' AND mpp.perfil IN (SELECT up.perfil FROM Usuariosperfiles AS up WHERE up.usuario=".$user_current["id"].")";

            $evaluar_propuestas = $app->modelsManager->executeQuery($phql);

            ?>

            <!-- Metis Menu Plugin JavaScript -->
            <script src="../../vendor/metisMenu/metisMenu.min.js"></script>

            <!-- Custom Theme JavaScript -->
            <script src="../../dist/js/sb-admin-2.js"></script>

            <div class="navbar-header">
                <button type="button" class="navbar-toggle" data-toggle="collapse" data-target=".navbar-collapse">
                    <span class="sr-only">Toggle navigation</span>
                    <span class="icon-bar"></span>
                    <span class="icon-bar"></span>
                    <span class="icon-bar"></span>
                </button>
                <a href="index.html"><img style="height: 50px; float: left" src="../../dist/img/logo-secretaria-cultura.png" alt="Sistemas de Convocatorias" title="Sistemas de Convocatorias" /></a>
                <a class="navbar-brand" style="padding-left: 0px !important; padding-right: 0px !important;" href="index.html">Sistemas de Convocatorias</a>
            </div>
            <!-- /.navbar-header -->

            <ul class="nav navbar-top-links navbar-right">
                <!--
                <li class="dropdown">
                    <a class="dropdown-toggle" data-toggle="dropdown" href="#">
                        <i class="fa fa-envelope fa-fw"></i> <i class="fa fa-caret-down"></i>
                    </a>
                    <ul class="dropdown-menu dropdown-messages">
                        <li>
                            <a href="#">
                                <div>
                                    <strong>John Smith</strong>
                                    <span class="pull-right text-muted">
                                        <em>Yesterday</em>
                                    </span>
                                </div>
                                <div>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Pellentesque eleifend...</div>
                            </a>
                        </li>
                        <li class="divider"></li>
                        <li>
                            <a href="#">
                                <div>
                                    <strong>John Smith</strong>
                                    <span class="pull-right text-muted">
                                        <em>Yesterday</em>
                                    </span>
                                </div>
                                <div>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Pellentesque eleifend...</div>
                            </a>
                        </li>
                        <li class="divider"></li>
                        <li>
                            <a href="#">
                                <div>
                                    <strong>John Smith</strong>
                                    <span class="pull-right text-muted">
                                        <em>Yesterday</em>
                                    </span>
                                </div>
                                <div>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Pellentesque eleifend...</div>
                            </a>
                        </li>
                        <li class="divider"></li>
                        <li>
                            <a class="text-center" href="#">
                                <strong>Read All Messages</strong>
                                <i class="fa fa-angle-right"></i>
                            </a>
                        </li>
                    </ul>
                </li>

                <li class="dropdown">
                    <a class="dropdown-toggle" data-toggle="dropdown" href="#">
                        <i class="fa fa-tasks fa-fw"></i> <i class="fa fa-caret-down"></i>
                    </a>
                    <ul class="dropdown-menu dropdown-tasks">
                        <li>
                            <a href="#">
                                <div>
                                    <p>
                                        <strong>Task 1</strong>
                                        <span class="pull-right text-muted">40% Complete</span>
                                    </p>
                                    <div class="progress progress-striped active">
                                        <div class="progress-bar progress-bar-success" role="progressbar" aria-valuenow="40" aria-valuemin="0" aria-valuemax="100" style="width: 40%">
                                            <span class="sr-only">40% Complete (success)</span>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </li>
                        <li class="divider"></li>
                        <li>
                            <a href="#">
                                <div>
                                    <p>
                                        <strong>Task 2</strong>
                                        <span class="pull-right text-muted">20% Complete</span>
                                    </p>
                                    <div class="progress progress-striped active">
                                        <div class="progress-bar progress-bar-info" role="progressbar" aria-valuenow="20" aria-valuemin="0" aria-valuemax="100" style="width: 20%">
                                            <span class="sr-only">20% Complete</span>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </li>
                        <li class="divider"></li>
                        <li>
                            <a href="#">
                                <div>
                                    <p>
                                        <strong>Task 3</strong>
                                        <span class="pull-right text-muted">60% Complete</span>
                                    </p>
                                    <div class="progress progress-striped active">
                                        <div class="progress-bar progress-bar-warning" role="progressbar" aria-valuenow="60" aria-valuemin="0" aria-valuemax="100" style="width: 60%">
                                            <span class="sr-only">60% Complete (warning)</span>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </li>
                        <li class="divider"></li>
                        <li>
                            <a href="#">
                                <div>
                                    <p>
                                        <strong>Task 4</strong>
                                        <span class="pull-right text-muted">80% Complete</span>
                                    </p>
                                    <div class="progress progress-striped active">
                                        <div class="progress-bar progress-bar-danger" role="progressbar" aria-valuenow="80" aria-valuemin="0" aria-valuemax="100" style="width: 80%">
                                            <span class="sr-only">80% Complete (danger)</span>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </li>
                        <li class="divider"></li>
                        <li>
                            <a class="text-center" href="#">
                                <strong>See All Tasks</strong>
                                <i class="fa fa-angle-right"></i>
                            </a>
                        </li>
                    </ul>

                </li>

                <li class="dropdown">
                    <a class="dropdown-toggle" data-toggle="dropdown" href="#">
                        <i class="fa fa-bell fa-fw"></i> <i class="fa fa-caret-down"></i>
                    </a>
                    <ul class="dropdown-menu dropdown-alerts">
                        <li>
                            <a href="#">
                                <div>
                                    <i class="fa fa-comment fa-fw"></i> New Comment
                                    <span class="pull-right text-muted small">4 minutes ago</span>
                                </div>
                            </a>
                        </li>
                        <li class="divider"></li>
                        <li>
                            <a href="#">
                                <div>
                                    <i class="fa fa-twitter fa-fw"></i> 3 New Followers
                                    <span class="pull-right text-muted small">12 minutes ago</span>
                                </div>
                            </a>
                        </li>
                        <li class="divider"></li>
                        <li>
                            <a href="#">
                                <div>
                                    <i class="fa fa-envelope fa-fw"></i> Message Sent
                                    <span class="pull-right text-muted small">4 minutes ago</span>
                                </div>
                            </a>
                        </li>
                        <li class="divider"></li>
                        <li>
                            <a href="#">
                                <div>
                                    <i class="fa fa-tasks fa-fw"></i> New Task
                                    <span class="pull-right text-muted small">4 minutes ago</span>
                                </div>
                            </a>
                        </li>
                        <li class="divider"></li>
                        <li>
                            <a href="#">
                                <div>
                                    <i class="fa fa-upload fa-fw"></i> Server Rebooted
                                    <span class="pull-right text-muted small">4 minutes ago</span>
                                </div>
                            </a>
                        </li>
                        <li class="divider"></li>
                        <li>
                            <a class="text-center" href="#">
                                <strong>See All Alerts</strong>
                                <i class="fa fa-angle-right"></i>
                            </a>
                        </li>
                    </ul>
                </li>
                -->
                <!-- /.dropdown -->
                <li class="dropdown">
                    <a class="dropdown-toggle" data-toggle="dropdown" href="#">
                        <i class="fa fa-user fa-fw"></i> <i class="fa fa-caret-down"></i>
                    </a>
                    <ul class="dropdown-menu dropdown-user">
                        <li><a href="../perfil/form.html"><i class="fa fa-user fa-fw"></i> Mi perfil</a>
                        </li>
                        <!--<li><a href="#"><i class="fa fa-gear fa-fw"></i> Settings</a></li>-->
                        <li class="divider"></li>
                        <li><a href="javascript:void(0)" onclick="logout()"><i class="fa fa-sign-out fa-fw"></i> Cerrar sesión</a>
                        </li>
                    </ul>
                    <!-- /.dropdown-user -->
                </li>
                <!-- /.dropdown -->
            </ul>
            <!-- /.navbar-top-links -->

            <div class="navbar-default sidebar" role="navigation">
                <div class="sidebar-nav navbar-collapse">

                    <ul class="nav" id="side-menu">
                        <!--
                        <li class="sidebar-search">
                            <div class="input-group custom-search-form">
                                <input type="text" class="form-control" placeholder="Search...">
                                <span class="input-group-btn">
                                    <button class="btn btn-default" type="button">
                                        <i class="fa fa-search"></i>
                                    </button>
                                </span>
                            </div>
                        </li>
                        -->
                        <li>
                            <a href="../index/index.html"><i class="fa fa-dashboard fa-fw"></i> Inicio</a>
                        </li>
                        <?php
                        if(count($permisos_panel_de_seguridad)>0)
                        {
                        ?>
                        <li>
                            <a href="#"><i class="fa fa-lock fa-fw"></i> Panel de Seguridad<span class="fa arrow"></span></a>
                            <ul class="nav nav-second-level">
                                <li>
                                    <a href="../usuarios/list.html">Usuarios</a>
                                    <a style="display: none" href="../usuarios/form.html">Usuarios</a>
                                </li>
                                <li>
                                    <a href="../seguridad/list.html">Seguridad</a>
                                </li>
                                <li>
                                    <a href="../perfiles/list.html">Perfiles</a>
                                    <a style="display: none" href="../perfiles/form.html">Perfiles</a>
                                </li>
                                <li>
                                    <a href="../modulos/list.html">Modulos</a>
                                    <a style="display: none" href="../modulos/form.html">Modulos</a>
                                </li>
                                <li>
                                    <a href="../permisos/list.html">Permisos</a>
                                    <a style="display: none" href="../permisos/form.html">Permisos</a>
                                </li>
                            </ul>
                            <!-- /.nav-second-level -->
                        </li>
                        <?php
                        }
                        ?>
                        <?php
                        if(count($permisos_administracion)>0)
                        {
                        ?>
                        <li>
                            <a href="#"><i class="fa fa-table fa-fw"></i> Administracion<span class="fa arrow"></span></a>
                            <ul class="nav nav-second-level">
                                <li>
                                    <a href="../tablasmaestras/list.html">Tablas maestras</a>
                                    <a style="display: none" href="../tablasmaestras/form.html">Tablas maestras</a>
                                </li>
                                <li>
                                    <a href="../paises/list.html">Paises</a>
                                    <a style="display: none" href="../paises/form.html">Paises</a>
                                </li>
                                <li>
                                    <a href="../departamentos/list.html">Departamentos</a>
                                    <a style="display: none" href="../departamentos/form.html">Departamentos</a>
                                </li>
                                <li>
                                    <a href="../ciudades/list.html">Ciudades</a>
                                    <a style="display: none" href="../ciudades/form.html">Ciudades</a>
                                </li>
                                <li>
                                    <a href="../localidades/list.html">Localidades</a>
                                    <a style="display: none" href="../localidades/form.html">Localidades</a>
                                </li>
                                <li>
                                    <a href="../upzs/list.html">Upzs</a>
                                    <a style="display: none" href="../upzs/form.html">Upzs</a>
                                </li>
                                <li>
                                    <a href="../barrios/list.html">Barrios</a>
                                    <a style="display: none" href="../barrios/form.html">Barrios</a>
                                </li>
                                <li>
                                    <a href="../entidades/list.html">Entidades</a>
                                    <a style="display: none" href="../entidades/form.html">Entidades</a>
                                </li>
                                <li>
                                    <a href="../tiposdocumentos/list.html">Tipos de documentos</a>
                                    <a style="display: none" href="../tiposdocumentos/form.html">Tipos de documentos</a>
                                </li>
                                <li>
                                    <a href="../tiposprogramas/list.html">Tipos de programas</a>
                                    <a style="display: none" href="../tiposprogramas/form.html">Tipos de programas</a>
                                </li>
                                <li>
                                    <a href="../tiposparticipantes/list.html">Tipos de participantes</a>
                                    <a style="display: none" href="../tiposparticipantes/form.html">Tipos de participantes</a>
                                </li>
                                <li>
                                    <a href="../tiposconvenios/list.html">Tipos de convenios</a>
                                    <a style="display: none" href="../tiposconvenios/form.html">Tipos de convenios</a>
                                </li>
                                <li>
                                    <a href="../tiposestimulos/list.html">Tipos de estimulos</a>
                                    <a style="display: none" href="../tiposestimulos/form.html">Tipos de estimulos</a>
                                </li>
                                <li>
                                    <a href="../tiposeventos/list.html">Tipos de Eventos</a>
                                    <a style="display: none" href="../tiposeventos/form.html">Tipos de Eventos</a>
                                </li>
                                <li>
                                    <a href="../estados/list.html">Estados</a>
                                    <a style="display: none" href="../estados/form.html">Estados</a>
                                </li>
                                <li>
                                    <a href="../sexos/list.html">Sexos</a>
                                    <a style="display: none" href="../sexos/form.html">Sexos</a>
                                </li>
                                <li>
                                    <a href="../orientacionessexuales/list.html">Orientaciones sexuales</a>
                                    <a style="display: none" href="../orientacionessexuales/form.html">Orientaciones sexuales</a>
                                </li>
                                <li>
                                    <a href="../identidadesgeneros/list.html">Identidades de generos</a>
                                    <a style="display: none" href="../identidadesgeneros/form.html">Identidades de generos</a>
                                </li>
                                <li>
                                    <a href="../niveleseducativos/list.html">Niveles educativos</a>
                                    <a style="display: none" href="../niveleseducativos/form.html">Niveles educativos</a>
                                </li>
                                <li>
                                    <a href="../programas/list.html">Programas</a>
                                    <a style="display: none" href="../programas/form.html">Programas</a>
                                </li>
                                <li>
                                    <a href="../modalidades/list.html">Modalidades</a>
                                    <a style="display: none" href="../modalidades/form.html">Modalidades</a>
                                </li>
                                <li>
                                    <a href="../areas/list.html">Areas</a>
                                    <a style="display: none" href="../areas/form.html">Areas</a>
                                </li>
                                <li>
                                    <a href="../areasconocimientos/list.html">Áreas de conocimientos</a>
                                    <a style="display: none" href="../areasconocimientos/form.html">Áreas de conocimientos</a>
                                </li>
                                <li>
                                    <a href="../lineasestrategicas/list.html">Líneas estratégicas</a>
                                    <a style="display: none" href="../lineasestrategicas/form.html">Líneas estratégicas</a>
                                </li>
                                <li>
                                    <a href="../enfoques/list.html">Enfoques</a>
                                    <a style="display: none" href="../enfoques/form.html">Enfoques</a>
                                </li>
                                <li>
                                    <a href="../coberturas/list.html">Coberturas</a>
                                    <a style="display: none" href="../coberturas/form.html">Coberturas</a>
                                </li>
                                <li>
                                    <a href="../recursosnopecuniarios/list.html">Recursos no pecuniarios</a>
                                    <a style="display: none" href="../recursosnopecuniarios/form.html">Recursos no pecuniarios</a>
                                </li>
                                <li>
                                    <a href="../requisitos/list.html">Requisitos</a>
                                    <a style="display: none" href="../requisitos/form.html">Requisitos</a>
                                </li>
                            </ul>
                            <!-- /.nav-second-level -->
                        </li>
                        <?php
                        }
                        ?>
                        <?php
                        if(count($permisos_convocatorias)>0)
                        {

                        $style_update="display: none";
                        $style_new="";
                        if($request->getPost('id')!="")
                        {
                            $style_update="";
                            $style_new="display: none";
                        }
                        ?>
                        <li>
                            <a href="#"><i class="fa fa-files-o fa-fw"></i> Convocatorias<span class="fa arrow"></span></a>
                            <ul class="nav nav-second-level">
                                <li>
                                    <a style="<?php echo $style_new;?>" href="../convocatorias/list.html">Buscar convocatoria</a>
                                    <a style="<?php echo $style_new;?>" href="../convocatorias/create.html">Crear convocatoria</a>
                                    <a style="<?php echo $style_update;?>" href="../convocatorias/update.html?id=<?php echo $request->getPost('id');?>">Información General</a>
                                    <a style="<?php echo $style_update;?>" href="../convocatorias/categorias.html?id=<?php echo $request->getPost('id');?>">Categorías</a>
                                    <a style="<?php echo $style_update;?>" href="../convocatorias/cronograma.html?id=<?php echo $request->getPost('id');?>">Cronograma</a>
                                    <a style="<?php echo $style_update;?>" href="../convocatorias/documentos_administrativos.html?id=<?php echo $request->getPost('id');?>">Doc. Administrativos</a>
                                    <a style="<?php echo $style_update;?>" href="../convocatorias/documentos_tecnicos.html?id=<?php echo $request->getPost('id');?>">Doc. Técnicos</a>
                                    <a style="<?php echo $style_update;?>" href="../convocatorias/rondas_evaluacion.html?id=<?php echo $request->getPost('id');?>">Rondas de evaluación</a>
                                    <a style="<?php echo $style_update;?>" href="../convocatorias/documentos_convocatorias.html?id=<?php echo $request->getPost('id');?>">Documentación</a>
                                    <a style="<?php echo $style_update;?>" href="../convocatorias/listados_convocatorias.html?id=<?php echo $request->getPost('id');?>">Listados</a>
                                    <a style="<?php echo $style_update;?>" href="../convocatorias/avisos_convocatorias.html?id=<?php echo $request->getPost('id');?>">Avisos</a>
                                    <a style="<?php echo $style_update;?>" href="../convocatorias/parametros_convocatorias.html?id=<?php echo $request->getPost('id');?>">Formulario de la propuesta</a>
                                    <a style="<?php echo $style_update;?>" href="<?php echo $config->sitio->url;?>publicar.html?id=<?php echo $request->getPost('id');?>" target="_blank">Ver Convocatoria</a>
                                </li>
                            </ul>
                            <!-- /.nav-second-level -->
                        </li>
                        <?php
                        }
                        ?>

                        <?php
                        if(count($permisos_propuestas)>0)
                        {
                        ?>
                        <li>
                            <a href="#"><i class="fa  fa-file-text-o fa-fw"></i> Propuestas<span class="fa arrow"></span></a>
                            <ul class="nav nav-second-level">
                                <li>
                                    <a style="" href="../administracionpropuestas/busqueda_propuestas.html">Búsqueda de propuestas</a>

                                    <a style="" href="../administracionpropuestas/verificacion_propuestas.html">Verificación de propuestas</a>

                                    <a style="" href="../administracionpropuestas/subsanacion_propuestas.html">Subsanación de propuestas</a>
                                </li>
                            </ul>
                            <!-- /.nav-second-level -->
                        </li>
                        <?php
                        }
                        ?>

                        <?php
                        if(count($permisos_menu_participante)>0)
                        {
                        ?>
                        <li>
                            <a href="#"><i class="fa fa-users fa-fw"></i> Perfiles del participante<span class="fa arrow"></span></a>
                            <ul class="nav nav-second-level">
                                <li>
                                    <a href="../perfilesparticipantes/persona_natural.html">Persona natural</a>
                                </li>
                                <li>
                                    <a href="../perfilesparticipantes/persona_juridica.html">Persona jurídica</a>
                                </li>
                                <li>
                                    <a href="../perfilesparticipantes/agrupacion.html">Agrupación</a>
                                </li>
                                <li>
                                    <a href="../perfilesparticipantes/jurado.html">Jurado</a>
                                </li>
                            </ul>
                            <!-- /.nav-second-level -->
                        </li>
                        <li>
                            <a href="#"><i class="fa fa-users fa-fw"></i> Convocatorias<span class="fa arrow"></span></a>
                            <ul class="nav nav-second-level">
                                <?php
                                //Solo se activa cuando no viaja el parametro de modalidad
                                if($request->getPost('m')=="")
                                {
                                ?>
                                <li><a href="../propuestas/propuestas_busqueda_convocatorias.html">Búsqueda de convocatorias</a></li>
                                <?php
                                }
                                ?>
                                <?php
                                //El sub menu de jurados, debido a la modalidad de la convocatoria
                                if($request->getPost('m')==2)
                                {
                                ?>
                                <li><a href="../propuestasjurados/perfil.html?m=<?php echo $request->getPost('m');?>&id=<?php echo $request->getPost('id');?>&p=<?php echo $request->getPost('p');?>">Información Básica</a></li>
                                <li><a href="../propuestasjurados/educacion_formal.html?m=<?php echo $request->getPost('m');?>&id=<?php echo $request->getPost('id');?>">Educación formal</a></li>
                                <li><a href="../propuestasjurados/educacion_no_formal.html?m=<?php echo $request->getPost('m');?>&id=<?php echo $request->getPost('id');?>">Educación no formal</a></li>
                                <li><a href="../propuestasjurados/experiencia_profesional.html?m=<?php echo $request->getPost('m');?>&id=<?php echo $request->getPost('id');?>">Experiencia disciplinar</a></li>
                                <li><a href="../propuestasjurados/experiencia_jurado.html?m=<?php echo $request->getPost('m');?>&id=<?php echo $request->getPost('id');?>">Experiencia como jurado de convocatorias</a></li>
                                <li><a href="../propuestasjurados/reconocimiento.html?m=<?php echo $request->getPost('m');?>&id=<?php echo $request->getPost('id');?>">Reconocimientos (Distinciones o Premios)</a></li>
                                <li><a href="../propuestasjurados/publicaciones.html?m=<?php echo $request->getPost('m');?>&id=<?php echo $request->getPost('id');?>">Publicaciones</a></li>
                                <li><a href="../propuestasjurados/documentos_administrativos.html?m=<?php echo $request->getPost('m');?>&id=<?php echo $request->getPost('id');?>">Documentos administrativos</a></li>
                                <li><a href="../propuestasjurados/postular_hoja_vida.html?m=<?php echo $request->getPost('m');?>&id=<?php echo $request->getPost('id');?>">Inscribir hoja de vida</a></li>
                                <li><a href="../propuestasjurados/postulaciones.html?m=<?php echo $request->getPost('m');?>&id=<?php echo $request->getPost('id');?>">Mis postulaciones</a></li>
                                <?php
                                }
                                ?>
                                <?php
                                //El sub menu de jurados, debido a la modalidad de la convocatoria
                                if($request->getPost('m')==1||$request->getPost('m')==3||$request->getPost('m')==4||$request->getPost('m')==5||$request->getPost('m')==6||$request->getPost('m')==7||$request->getPost('m')==8)
                                {
                                ?>
                                <li><a href="../propuestas/propuestas_busqueda_convocatorias.html">Búsqueda de convocatorias</a></li>
                                <li><a href="../propuestas/perfiles.html?m=<?php echo $request->getPost('m');?>&id=<?php echo $request->getPost('id');?>&p=<?php echo $request->getPost('p');?>">Términos y condiciones de participación</a></li>
                                <?php
                                }
                                ?>
                                <?php
                                if( $request->getPost('m')=="pn" )
                                {
                                ?>
                                <li><a href="../propuestas/propuestas_busqueda_convocatorias.html">Búsqueda de convocatorias</a></li>
                                <li><a href="../propuestas/perfiles.html?m=1&id=<?php echo $request->getPost('id');?>&p=<?php echo $request->getPost('p');?>">Términos y condiciones de participación</a></li>
                                <li><a href="../propuestas/perfil_persona_natural.html?m=<?php echo $request->getPost('m');?>&id=<?php echo $request->getPost('id');?>&p=<?php echo $request->getPost('p');?>">Persona natural</a></li>
                                <li><a href="../propuestas/propuestas.html?m=<?php echo $request->getPost('m');?>&id=<?php echo $request->getPost('id');?>&p=<?php echo $request->getPost('p');?>">Propuesta</a></li>
                                <li><a href="../propuestas/documentacion.html?m=<?php echo $request->getPost('m');?>&id=<?php echo $request->getPost('id');?>&p=<?php echo $request->getPost('p');?>">Documentación</a></li>
                                <?php
                                }
                                ?>
                                <?php
                                if( $request->getPost('m')=="pj" )
                                {
                                ?>
                                <li><a href="../propuestas/propuestas_busqueda_convocatorias.html">Búsqueda de convocatorias</a></li>
                                <li><a href="../propuestas/perfiles.html?m=1&id=<?php echo $request->getPost('id');?>&p=<?php echo $request->getPost('p');?>">Términos y condiciones de participación</a></li>
                                <li><a href="../propuestas/perfil_persona_juridica.html?m=<?php echo $request->getPost('m');?>&id=<?php echo $request->getPost('id');?>&p=<?php echo $request->getPost('p');?>">Persona jurídica</a></li>
                                <li><a href="../propuestas/propuestas.html?m=<?php echo $request->getPost('m');?>&id=<?php echo $request->getPost('id');?>&p=<?php echo $request->getPost('p');?>">Propuesta</a></li>
                                <li><a href="../propuestas/junta.html?m=<?php echo $request->getPost('m');?>&id=<?php echo $request->getPost('id');?>&p=<?php echo $request->getPost('p');?>">Junta directiva</a></li>
                                <li><a href="../propuestas/documentacion.html?m=<?php echo $request->getPost('m');?>&id=<?php echo $request->getPost('id');?>&p=<?php echo $request->getPost('p');?>">Documentación</a></li>
                                <?php
                                }
                                ?>
                                <?php
                                if( $request->getPost('m')=="agr" )
                                {
                                ?>
                                <li><a href="../propuestas/propuestas_busqueda_convocatorias.html">Búsqueda de convocatorias</a></li>
                                <li><a href="../propuestas/perfiles.html?m=1&id=<?php echo $request->getPost('id');?>&p=<?php echo $request->getPost('p');?>">Términos y condiciones de participación</a></li>
                                <li><a href="../propuestas/perfil_agrupacion.html?m=<?php echo $request->getPost('m');?>&id=<?php echo $request->getPost('id');?>&p=<?php echo $request->getPost('p');?>">Agrupación</a></li>
                                <li><a href="../propuestas/propuestas.html?m=<?php echo $request->getPost('m');?>&id=<?php echo $request->getPost('id');?>&p=<?php echo $request->getPost('p');?>">Propuesta</a></li>
                                <li><a href="../propuestas/integrantes.html?m=<?php echo $request->getPost('m');?>&id=<?php echo $request->getPost('id');?>&p=<?php echo $request->getPost('p');?>">Integrantes</a></li>
                                <li><a href="../propuestas/documentacion.html?m=<?php echo $request->getPost('m');?>&id=<?php echo $request->getPost('id');?>&p=<?php echo $request->getPost('p');?>">Documentación</a></li>
                                <?php
                                }
                                ?>

                            </ul>
                            <!-- /.nav-second-level -->
                        </li>
                        <li>
                            <a href="#"><i class="fa  fa-file-text-o fa-fw"></i> Propuestas<span class="fa arrow"></span></a>
                            <ul class="nav nav-second-level">
                                <li>
                                    <a href="../propuestas/mis_propuestas.html">Mis propuestas</a>

                                      <!--Cesar Britto, 2020-01-17-->
                        <?php

                        if( count($evaluar_propuestas) > 0 )
                        {
                            ?>
                                    <a style="" href="../administracionpropuestas/evaluacion_propuestas.html">Evaluar propuestas</a>

                        <?php
                        }
                        ?>
                                </li>
                                <?php
                                $style_update="display: none";
                                if($request->getPost('sub')!="")
                                {
                                    $style_update="display: block";
                                }
                                ?>
                                <li>
                                    <a style="<?php echo $style_update;?>" href="../propuestas/subsanar_propuesta.html?id=<?php echo $request->getPost('id');?>&p=<?php echo $request->getPost('p');?>&sub=<?php echo $request->getPost('sub');?>">Subsanar propuesta</a>

                                </li>
                            </ul>
                            <!-- /.nav-second-level -->
                        </li>
                        <?php
                        }
                        ?>
                        <!--Cesar Britto-->
                        <?php
                        if( count($permisos_jurados) > 0 )
                        {

                        $style_update="display: none";
                        $style_new="";
                        if($request->getPost('id')!="")
                        {
                            $style_update="";
                            $style_new="display: none";
                        }
                        ?>
                        <li>
                            <a href="#"><i class="fa fa-users fa-fw"></i> Jurados<span class="fa arrow"></span></a>
                            <ul class="nav nav-second-level">
                                <li>
                                    <a style="<?php echo $style_new;?>" href="../jurados/preseleccion.html">Preselección</a>

                                </li>
                                <li>
                                    <a style="<?php echo $style_new;?>" href="../jurados/seleccion.html">Selección de jurados</a>

                                </li>
                                <li>
                                    <a style="<?php echo $style_new;?>" href="../jurados/gruposevaluacion.html">Grupos de evaluación</a>

                                </li>
                            </ul>
                            <!-- /.nav-second-level -->
                        </li>
                        <?php
                        }
                        ?>

                    </ul>
                </div>
                <!-- /.sidebar-collapse -->
            </div>
            <!-- /.navbar-static-side -->
            <?php
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        echo "error_metodo" . $ex;
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
