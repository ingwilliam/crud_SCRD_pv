<?php
use Phalcon\Mvc\Model;

class Gruposevaluadoresrondas extends Model
{
    public $id;

    public function initialize()
    {
        //Se define relacion de N a 1 con Convocatorias
        $this->belongsTo(
            'convocatoriaronda',
            'Convocatoriasrondas',
            'id',
            [
                'foreignKey' => true
            ]
        );
        
        //Se define relacion de N a 1
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
