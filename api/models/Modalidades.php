<?php
use Phalcon\Mvc\Model;

class Modalidades extends Model
{
    public $id;
    
    public function initialize()
    {
        //Se define relacion de N a 1 con Programas
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