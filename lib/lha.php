<?php
/*
 * $lha = new LHA('./hoge.lzh');
 * echo $lha->header->to_str();
 * $lha->extract();
 */

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

class LHAExtract{
    /* limits.h */
    private static $CHAR_BIT = 8;
    private static $UCHAR_MAX = 255;
    private static $ULONG_MAX = 4294967295;

    /* io.c */
    private static $INIT_CRC = 0;
    private static $BITBUFSIZ;

    /* decode.c */
    private static $DICBIT = 13;    /* 12(-lh4-) or 13(-lh5-) */
    private static $DICSIZ;
    private static $MAXMATCH = 256; /* formerly F (not more than UCHAR_MAX + 1) */
    private static $THRESHOLD = 3;  /* choose optimal value */

    /* huf.c */
    private static $NC;
    private static $NP;
    private static $NT;
    private static $PBIT = 4;
    private static $TBIT = 5;
    private static $NPT;

	/* alphabet = {0, 1, 2, ..., NC - 1} */
    private static $CBIT = 9;  /* $\lfloor \log_2 NC \rfloor + 1$ */
    private static $CODE_BIT = 16;  /* codeword length */

    private $lha;

    /* huf.c */
    private $left;
    private $right;
    private $c_len;
    private $pt_len;
    private $c_freq;
    private $c_table;
    private $c_code;
    private $p_freq;
    private $pt_table;
    private $pt_code;
    private $t_freq;

    private $buffer;
    private $j;  /* remaining bytes to copy */
    private $blocksize;
    private $bitbuf;
    private $subbitbuf;
    private $bitcount;
    private $outfile;
    private $arcfile;
    private $compsize;
    private $is_tofile;

    public function  __construct($lha,$outfile = null){
        self::$DICSIZ = 1 << self::$DICBIT;
        self::$BITBUFSIZ = self::$CHAR_BIT * 4;

        self::$NC = (self::$UCHAR_MAX + self::$MAXMATCH + 2 - self::$THRESHOLD);

        self::$NP = (self::$DICBIT + 1);
        self::$NT = (self::$CODE_BIT +3);
        if (self::$NT > self::$NP){
            self::$NPT = self::$NT;
        } else {
            self::$NPT = self::$NP;
        }


        $this->lha = $lha;

        /* huf.c */
        $this->left = array();
        $this->right = array();
        $this->c_len = array();
        $this->c_freq = array();
        $this->c_table = array();
        $this->c_code = array();
        $this->p_freq = array();
        $this->pt_table = array();
        $this->pt_code = array();
        $this->t_freq = array();

        $this->buffer = array();

        $this->bitbuf = 0;
        $this->subbitbuf = 0;
        $this->bitcount = 0;

        $this->blocksize = 0;
        $this->j = 0;
        if (is_null($outfile)) {
            $this->outfile = fopen($lha->header->filename,"wb");
            $this->is_tofile = true;
        } else {
            $this->outfile = $outfile;
            $this->is_tofile = false;
        }
        $this->arcfile = fopen($lha->file_path,"rb");
        fseek($this->arcfile,$lha->header->headersize+2);
        $this->compsize = $lha->header->packed;
    }

    public function extract(){
        $n;
        $crc = 0;
        $origsize = $this->lha->header->original;
        if (!ereg("-lh[045]-",$this->lha->header->method)) {
            throw new Exception("not support method type");
            return;
        } else {
            if ($this->lha->header->method != '-lh0-') $this->decode_start();
            while ($origsize != 0) {
                $n = ($origsize > self::$DICSIZ) ? self::$DICSIZ : $origsize;
                if ($this->lha->header->method != '-lh0-') {
                    $this->decode($n, $this->buffer);
                } else {
                    $this->buffer = fread($this->arcfile, $n);
                    if( strlen($this->buffer) != $n) {
                        throw new Exception("error");
                    }
                }
                $this->fwrite_crc($this->buffer, $n, $this->outfile);
                $origsize -= $n;
            }
        }
        if ($this->is_tofile) fclose($this->outfile);  else $this->outfile = null;
//        if (($crc ^ self::INIT_CRC) != file_crc)
//            console.logerror("CRC error\n");
    }

    private function fillbuf($n){
        $this->bitbuf = ($this->bitbuf << $n) & 0xFFFFFFFF;

        while ($n > $this->bitcount) {
            $this->bitbuf |= ($this->subbitbuf << ($n -= $this->bitcount)) & 0xFFFFFFFF;

            if ($this->compsize != 0) {
                $this->compsize--;  $this->subbitbuf = ord(fgetc($this->arcfile));
            } else $this->subbitbuf = 0;
            $this->bitcount = self::$CHAR_BIT;
        }
        $this->bitbuf |= logic_shift($this->subbitbuf, $this->bitcount -= $n);
        printf("fillbuff: %x\n",$this->bitbuf);
    }

    private function getbits($n) {
        if($n == 0) return 0;
        $x = logic_shift($this->bitbuf, self::$BITBUFSIZ - $n);
        $this->fillbuf($n);
        return $x;
    }

    private function fwrite_crc($p, $n, $f) {
        for($i = 0;$i<$n;$i++){
            if (fwrite($f,chr($p[$i]), $n) < 1) throw new Exception("Unable to write");
        }
//        while (--n >= 0) UPDATE_CRC(p[i++]);
    }

    private function init_getbits() {
        $this->bitbuf = 0;
        $this->subbitbuf = 0;
        $this->bitcount = 0;
        $this->fillbuf(self::$BITBUFSIZ);
    }


    private function make_table($nchar, $bitlen, $tablebits,$table) {
        $count = array();
        $weight = array();
        $start = array();
        for($i = 0; $i <= 16; $i++) { $count[$i] = 0;}
        for($i = 0; $i < $nchar; $i++) { $count[$bitlen[$i]]++; }

        $start[1] = 0;
        for ($i = 1; $i <= 16; $i++) {
            $start[$i + 1] = ($start[$i] + ($count[$i] << (16 - $i))) & 0xFFFF;
        }

        if ($start[17] != ((1 << 16) & 0xFFFF)) throw Exception("Bad table");

        $jutbits = 16 - $tablebits;
        for ($i = 1; $i <= $tablebits; $i++) {
            $start[$i] = logic_shift($start[$i],$jutbits);
            $weight[$i] = 1 << ($tablebits - $i);
        }
        while ($i <= 16) {
            $weight[$i] = 1 << (16 - $i);  $i++;
        }
        $i = logic_shift($start[$tablebits + 1], $jutbits);
        if ($i != ((1 << 16) & 0xFFFF)) {
            $k = 1 << $tablebits;
            while ($i != $k) $table[$i++] = 0;
        }

        $avail = $nchar;
        $mask = 1 << (15 - $tablebits);
        for ($ch = 0; $ch < $nchar; $ch++) {
            if (($len = $bitlen[$ch]) == 0) continue;
            $nextcode = $start[$len] + $weight[$len];
           if ($len <= $tablebits) {
                for ($i = $start[$len]; $i < $nextcode; $i++) $table[$i] = $ch;
            } else {
                $k = $start[$len];
                $p = &$table;
                $l = logic_shift($k, $jutbits);
                $i = $len - $tablebits;
                while ($i != 0) {
                    if ($p[$l] == 0) {
                        $this->right[$avail] = 0;
                        $this->left[$avail] = 0;
                        $p[$l] = $avail++;
                    }
                    if ($k & $mask){
                        $l = $p[$l];
                        $p = &$this->right;
                    } else {
                        $l = $p[$l];
                        $p = &$this->left;
                    }
                    $k <<= 1;  $i--;
                }
                $p[$l] = $ch;
            }
            $start[$len] = $nextcode;
        }
        return $table;
    }

    private function read_pt_len($nn, $nbit, $i_special) {
        $i = 0;
        $c = 0;
        $n = 0;
        $mask = 0;

        $n = $this->getbits($nbit);
        if ($n == 0) {
            $c = $this->getbits($nbit);
            for ($i = 0; $i < $nn; $i++) $this->pt_len[$i] = 0;
            for ($i = 0; $i < 256; $i++) $this->pt_table[$i] = $c;
        } else {
            $i = 0;
            while ($i < $n) {
                $c = logic_shift($this->bitbuf,self::$BITBUFSIZ - 3);
                if ($c == 7) {
                    $mask = 1 << (self::$BITBUFSIZ - 1 - 3);
                    while ($mask & $this->bitbuf) {
                        $mask = logic_shift($mask,1);
                        $c++;
                    }
                }
                $this->fillbuf(($c < 7) ? 3 : $c - 3);
                $this->pt_len[$i++] = $c;
                if ($i == $i_special) {
                    $c = $this->getbits(2);
                    while (--$c >= 0) $this->pt_len[$i++] = 0;
                }
            }
            while ($i < $nn) $this->pt_len[$i++] = 0;
            $this->pt_table = $this->make_table($nn, $this->pt_len, 8, $this->pt_table);
        }
    }

    private function read_c_len() {
        $i = 0;
        $c = 0;
        $n = 0;
        $mask = 0;

        $n = $this->getbits(self::$CBIT);
        if ($n == 0) {
            $c = $this->getbits(self::$CBIT);
            for ($i = 0; $i < self::$NC; $i++) $this->c_len[$i] = 0;
            for ($i = 0; $i < 4096; $i++) $c_table[$i] = $c;
        } else {
            $i = 0;
            while ($i < $n) {
                $c = $this->pt_table[logic_shift($this->bitbuf,self::$BITBUFSIZ - 8)];
                if ($c >= self::$NT) {
                    $mask = 1 << (self::$BITBUFSIZ - 1 - 8);
                    do {
                        if ($this->bitbuf & $mask) $c = $this->right[$c];
                        else               $c = $this->left[$c];
                        $mask = logic_shift($mask,1);
                    } while ($c >= self::$NT);
                }
                $this->fillbuf($this->pt_len[$c]);
                if ($c <= 2) {
                    if      ($c == 0) $c = 1;
                    else if ($c == 1) $c = $this->getbits(4) + 3;
                    else             $c = $this->getbits(self::$CBIT) + 20;
                    while (--$c >= 0) $this->c_len[$i++] = 0;
                } else $this->c_len[$i++] = $c - 2;
            }
            while ($i < self::$NC) $this->c_len[$i++] = 0;
            $this->c_table = $this->make_table(self::$NC, $this->c_len, 12, $this->c_table);
        }
    }

    private function decode_c() {
        $j = 0;
        $mask = 0;
        if ($this->blocksize == 0) {
            $this->blocksize = $this->getbits(16);
            $this->read_pt_len(self::$NT, self::$TBIT, 3);

            $this->read_c_len();
            $this->read_pt_len(self::$NP, self::$PBIT, -1);
        }
        $this->blocksize--;
        $j = $this->c_table[logic_shift($this->bitbuf, self::$BITBUFSIZ - 12)];
        if ($j >= self::$NC) {
            $mask = 1 << (self::$BITBUFSIZ - 1 - 12);
            do {
                if ($this->bitbuf & $mask) $j = $this->right[$j];
                else               $j = $this->left [$j];
                $mask = logic_shift($mask,1);
            } while ($j >= self::$NC);
        }
        $this->fillbuf($this->c_len[$j]);
        if($j == 0){ exit(0);}
        return $j;
    }

    private function decode_p() {
        $j = $this->pt_table[logic_shift($this->bitbuf,self::$BITBUFSIZ - 8)];
        if ($j >= self::$NP) {
            $mask = 1 << (self::$BITBUFSIZ - 1 - 8);
            do {
                if ($this->bitbuf & $mask) $j = $this->right[$j];
                else               $j = $this->left [$j];
                $mask = logic_shift($mask, 1);
            } while ($j >= self::$NP);
        }
        $this->fillbuf($this->pt_len[$j]);
        if ($j != 0) $j = (1 << ($j - 1)) + $this->getbits($j - 1);
        return $j;
    }

    private function huf_decode_start(){
        $this->init_getbits();
        $this->blocksize = 0;
    }

    private function decode_start(){
        $this->huf_decode_start();
        $this->j = 0;
    }

    private function decode($count,$buffer){
        static $i;
        $r = 0;
        $c = 0;
        while (--$this->j >= 0){
            $this->buffer[$r] = $this->buffer[$i];
            $i = ($i + 1) & (self::$DICSIZ - 1);
            if (++$r == $count) return;
        }
        for ( ; ; ) {
            $c = $this->decode_c();

            if ($c <= self::$UCHAR_MAX) {
                $this->buffer[$r] = $c;
                if (++$r == $count) return;
            } else {
                $this->j = $c - (self::$UCHAR_MAX + 1 - self::$THRESHOLD);
                $i = ($r - $this->decode_p() - 1) & (self::$DICSIZ - 1);
                while (--$this->j >= 0) {
                    $this->buffer[$r] = $this->buffer[$i];
                    $i = ($i + 1) & (self::$DICSIZ - 1);
                    if (++$r == $count) return;
                }
            }
        }
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
        $ext = new LHAExtract($this);
        $ext->extract();
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