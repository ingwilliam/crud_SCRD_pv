<?php
use Phalcon\Mvc\Model;

class Convocatoriasrondas extends Model
{
    public $id;
    /*Cesar Britto, 20-04-2020*/
    public $estado_nombre;

    public function initialize()
    {
        //Se define relacion de N a 1 con Convocatorias
        $this->belongsTo(
            'convocatoria',
            'Convocatorias',
            'id',
            [
                'foreignKey' => true
            ]
        );

        //hasMany 	Defines a 1-n relationship
        $this->hasMany(
            'id',
            'Convocatoriasrondascriterios',
            'convocatoria_ronda'
        );

        //Se define relacion de N a 1
        $this->belongsTo(
            'grupoevaluador',
            'Gruposevaluadores',
            'id'
        );


    }

    /*Cesar Britto, 20-04-2020*/
    public function getEstado_nombre()
    {
        /*Ajuste de william supervisado por wilmer*/
        /*2020-04-28*/
        $array_estado_actual_1=Estados::findFirst(" id = ". $this->estado);
        
        
      return $array_estado_actual_1->nombre;
    }



}
