<?php
use Phalcon\Mvc\Model;

class Evaluacioncriterios extends Model
{
    public $id;

    public function initialize()
    {
        //Se define la relación con  n-1 con Propuestas
        $this->belongsTo(
            'evaluacionpropuesta',
            'Evaluacionpropuestas',
            'id'
        );
        

    }
        
    public function validation(){        
        
        if ($this->puntaje == "") {
            $this->puntaje = null;
        }        
        
        if ($this->puntaje == 'null') {
            $this->puntaje = null;
        }
        
        return true;
    }
}
