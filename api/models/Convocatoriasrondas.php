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



    }


}
