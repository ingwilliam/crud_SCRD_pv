<?php
use Phalcon\Mvc\Model;

class Tiposeventos extends Model
{
    public $id;
    
    public function initialize()
    {
        //Se define relacion de N a 1 con Paises
        $this->belongsTo(
            'programa',
            'Programas',
            'id',
            [
                'foreignKey' => true
            ]
        );
        
    }
}