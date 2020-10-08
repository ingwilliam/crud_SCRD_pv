<?php
use Phalcon\Mvc\Model;

class Propuestaspresupuestos extends Model
{
    public $id;
    
    public function initialize()
    {                
        //Se define relacion de N a 1 con Paises
        $this->belongsTo(
            'propuestaactividad',
            'Propuestaspresupuestos',
            'id'            
        );
    }    
}