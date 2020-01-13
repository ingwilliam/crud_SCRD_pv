<?php
use Phalcon\Mvc\Model;

class Convocatoriasrondas extends Model
{
    public $id;

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

        //Se define relacion de N a 1 con Convocatorias
        $this->belongsTo(
            'grupoevaluador',
            'Gruposevaluadores',
            'id',
            [
                'foreignKey' => true
            ]
        );


    }


}
