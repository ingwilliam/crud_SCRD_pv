<?php
use Phalcon\Mvc\Model;

class Evaluadores extends Model
{
    public $id;

    public function initialize()
    {
        //Se define la relación con 1 a N con Convocatorias
        $this->belongsTo(
            'grupoevaluador',
            'Gruposevaluadores',
            'id'
        );
    }

}
