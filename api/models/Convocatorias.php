<?php

use Phalcon\Mvc\Model;

class Convocatorias extends Model {

    public $id;

    public function initialize() {
        //Se define la relación con 1 a N con Convocatoriaspropuestasparametros
        $this->hasMany(
                'id', 'Convocatoriaspropuestasparametros', 'convocatoria'
        );

        //Se define la relación con 1 a N con Convocatoriasrecursos
        $this->hasMany(
                'id', 'Convocatoriasrecursos', 'convocatoria'
        );

        //Cesar Britto
        /* Se define la relación con 1 a N con Convocatorias, con el fin de
         * obtener las categorias (Convocatorias)
         */
        $this->hasMany(
                'id',
                'Convocatorias',
                'convocatoria_padre_categoria',
                //Cesar Britto
                /* Se define el alias para obtener las categorias (Convocatorias)
                 */
                [
                  'alias' => 'Categorias',
                ]
        );

        //Cesar Britto
        /* Se define la relación con 1 a N con Convocatoriasrondas, con el fin
          de obtener las rondas */
        $this->hasMany(
                'id', 'Convocatoriasrondas', 'convocatoria'
        );

        //Se define relacion de N a 1 con Programas
        $this->belongsTo(
                'programa', 'Programas', 'id', [
                'foreignKey' => true
                ]
        );

        //Se define relacion de N a 1 con Entidades
        $this->belongsTo(
                'entidad', 'Entidades', 'id', [
                'foreignKey' => true
                ]
        );

        //Se define relacion de N a 1 con Estados
        $this->belongsTo(
                'estado', 'Estados', 'id'
        );

        //Se define relacion de N a 1 con Lineasestrategicas
        $this->belongsTo(
                'linea_estrategica', 'Lineasestrategicas', 'id'
        );

        //Se define relacion de N a 1 con Areas
        $this->belongsTo(
                'area', 'Areas', 'id'
        );

        //Cesar Britto
        /* Se define la relación con N a 1 con Propuestas, con el fin
          de obtener la propuesta */
        $this->belongsTo(
                'id',
                'Propuestas',
                'convocatoria'
        );

        //Cesar Britto
        //hasMany	Defines a 1-n relationship
        $this->hasMany(
            'id',
            'Juradospostulados',
            'convocatoria'
        );

        //Cesar Britto
        //hasMany	Defines a 1-n relationship
        $this->hasMany(
            'id',
            'Convocatoriascronogramas',
            'convocatoria'
        );


        //23 oct 2019->William Barbosa
        //Se define relacion de N a 1 con convocatoria padre
        $this->belongsTo(
            'convocatoria_padre_categoria',
            'Convocatorias',
            'id'
        );

        //14 oct 2019->Cesar Britto
        //hasMany	Defines a 1-n relationship
        $this->hasMany(
            'id',
            'Convocatoriasparticipantes',
            'convocatoria'
        );

    }

}
