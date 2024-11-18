<?php

class MathCalc {
    public static function round ($digit, $precission) {
        return $precission*ceil($digit/$precission);
    }
}