<?php
use Phalcon\Mvc\Model;

class Convocatoriasrondascriterios extends Model
{
    public $id;

    public function initialize()
    {
        //Se define relacion de N a 1 con Convocatorias
        $this->belongsTo(
            'convocatoria_ronda',
            'Convocatoriasrondas',
            'id',
            [
                'foreignKey' => true
            ]
        );

        //hasMany 	Defines a 1-n relationship
        $this->hasMany(
            'id',
            'Evaluacion',
            'criterio'
        );

    }


}
