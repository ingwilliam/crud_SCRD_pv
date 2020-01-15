<?php
use Phalcon\Mvc\Model;

class Juradosnotificaciones extends Model
{



    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("juradosnotificaciones");
        $this->belongsTo('juradospostulado', 'Juradospostulados', 'id', ['alias' => 'Juradospostulados']);
    }


}
