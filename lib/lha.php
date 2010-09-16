<?php
/*
 * $lha = new LHA('./hoge.lzh');
 * echo $lha->header->to_str();
 * $lha->extract();
 */

require("lha_extract.php");

function logic_shift($n,$bit) {
    $mask = ~(-1 << (32 - $bit));
    return $mask & ($n >> $bit);
}

/* 参考
 * http://gcc524.sakura.ne.jp/memo/index.cgi?p=LZH%A5%D5%A5%A1%A5%A4%A5%EB%A5%D5%A5%A9%A1%BC%A5%DE%A5%C3%A5%C8
*/
class LHAHeaderLevel1 {
    public $headersize;
    public $checksum;
    public $method;
    public $packed;
    public $original;
    public $time;
    public $date;
    public $attrib;
    public $level;
    public $namelen;
    public $filename;
    public $filecrc;
    public $OSmark;

    public static function parse($binary_header){
        $header = new LHAHeaderLevel1();
        $header->headersize = ord($binary_header[0]);
        $header->checksum = ord($binary_header[1]);
        $header->method = substr($binary_header,2,5);
        $tmp = unpack("L",substr($binary_header,7,4));
        $header->packed = $tmp[1];
        $tmp = unpack("L",substr($binary_header,0xb,4));
        $header->original = $tmp[1];
        $tmp = unpack("S",substr($binary_header,0xf,2));
        $header->time = $tmp[1];
        $tmp = unpack("S",substr($binary_header,0x11,2));
        $header->date = $tmp[1];
        $header->attrib = ord($binary_header[0x13]);
        $header->level = 1;
        $header->namelen = ord($binary_header[0x15]);
        $header->filename = substr($binary_header,0x16,$header->namelen);
        $filecrc_base = 0x16 + $header->namelen;
        $tmp = unpack("S",substr($binary_header,$filecrc_base,2));
        $header->filecrc = $tmp[1];
        $header->OSmark = $binary_header[$filecrc_base + 2];
        return $header;
    }

    public function to_str(){
        $ret = "";
        $ret .= sprintf("%8s: %d\n", "headsize", $this->headersize);
        $ret .= sprintf("%8s: %s\n", "method", $this->method);
        $ret .= sprintf("%8s: %d\n", "packed", $this->packed);
        $ret .= sprintf("%8s: %d\n", "original", $this->original);
        $ret .= sprintf("%8s: %d\n", "time" ,$this->time);
        $ret .= sprintf("%8s: %d\n", "date" ,$this->date);
        $ret .= sprintf("%8s: %d\n", "level", $this->level);
        $ret .= sprintf("%8s: %d\n", "namelen", $this->namelen);
        $ret .= sprintf("%8s: %s\n", "filename", $this->filename);
        $ret .= sprintf("%8s: %d\n", "filecrc", $this->filecrc);
        $ret .= sprintf("%8s: %s\n", "OSmark", $this->OSmark);
        return $ret;
    }
}


class LHA {
    private $file_path;
    private $header;

    public function  __construct($file_path){
        $this->file_path = $file_path;
        $this->parse();
    }

    public function parse(){
        $binary_header = $this->read_binary_head();
        $this->header = LHAHeaderLevel1::parse($binary_header);
    }

    public function extract(){
        lha_extract($this);
    }

    // setter and getter
    public function __get($name){
        switch($name){
        case "header":
            return $this->header;
        case "file_path":
            return $this->file_path;
        }
    }

    private function read_head_size(){
        $size = file_get_contents($this->file_path,NULL,NULL,0,1);
        return ord($size[0]);
    }
    private function read_binary_head(){
        return file_get_contents($this->file_path,
                                 NULL,
                                 NULL,
                                 0,
                                 $this->read_head_size());
    }
}
?>