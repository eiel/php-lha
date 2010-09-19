<?php
/* limits.h */
define("CHAR_BIT",8);
define("UCHAR_MAX",255);
define("ULONG_MAX",4294967295);

/* io.c */
define("INIT_CRC",0);
define("BITBUFSIZ",CHAR_BIT*4);

/* decode.c */
define("DICBIT",13);    /* 12(-lh4-) or 13(-lh5-) */
define("DICSIZ",1 << DICBIT);
define("MAXMATCH",256); /* formerly F (not more than UCHAR_MAX + 1) */
define("THRESHOLD",3);  /* choose optimal value */

/* alphabet = {0, 1, 2, ..., NC - 1} */
define("CBIT",9);  /* $\lfloor \log_2 NC \rfloor + 1$ */
define("CODE_BIT",16);  /* codeword length */

/* huf.c */
define("NC",UCHAR_MAX + MAXMATCH + 2 - THRESHOLD);

define("NP",DICBIT + 1);
define("NT",CODE_BIT +3);
if (NT > NP){
    define("NPT",NT);
} else {
    define("NPT",NP);
}
define("PBIT",4);
define("TBIT",5);

/* huf.c */
$left = array();
$right = array();
$c_len = array();
$c_freq = array();
$c_table = array();
$c_code = array();
$p_freq = array();
$pt_table = array();
$pt_code = array();
$t_freq = array();

$buffer = " ";

$bitbuf = 0;
$subbitbuf = 0;
$bitcount = 0;

$blocksize = 0;
$j = 0;
$outfile = 0;
$is_tofile = true;

$arcfile = 0;
$compsize = 0;

function  lha_extract($lha){
    global $outfile,$arcfile,$compsize;
    global $buffer,$is_tofile;
    $outfile = fopen($lha->header->filename,"wb");
    $arcfile = fopen($lha->file_path,"rb");
    fseek($arcfile,$lha->header->headersize+2);
    $compsize = $lha->header->packed;

    $n;
    $crc = 0;
    $origsize = $lha->header->original;
    if (!ereg("-lh[045]-",$lha->header->method)) {
        throw new Exception("not support method type");
        return;
    } else {
        if ($lha->header->method !== '-lh0-') decode_start();
        while ($origsize !== 0) {
            $n = ($origsize > DICSIZ) ? DICSIZ : $origsize;
            if ($lha->header->method !== '-lh0-') {
                decode($n, $buffer);
            } else {
                $buffer = fread($arcfile, $n);
                if( strlen($buffer) !== $n) {
                    throw new Exception("error");
                }
            }
            fwrite_crc($buffer, $n, $outfile);
            $origsize -= $n;
        }
    }
    if ($is_tofile) fclose($outfile);  else $outfile = null;
//        if (($crc ^ INIT_CRC) !== file_crc)
//            console.logerror("CRC error\n");
}

function fillbuf($n){
    global $bitbuf,$bitcount,$compsize,$subbitbuf,$arcfile;
    $bitbuf = ($bitbuf << $n) & 0xFFFFFFFF;

    while ($n > $bitcount) {
        $bitbuf |= ($subbitbuf << ($n -= $bitcount)) & 0xFFFFFFFF;

        if ($compsize !== 0) {
            $compsize--;  $subbitbuf = ord(fgetc($arcfile));
        } else $subbitbuf = 0;
        $bitcount = CHAR_BIT;
    }
    $bitbuf |= logic_shift($subbitbuf, $bitcount -= $n);
}

function getbits($n) {
    global $bitbuf;
    if($n === 0) {return 0;}
    $x = logic_shift($bitbuf, BITBUFSIZ - $n);
    fillbuf($n);
    return $x;
}

function fwrite_crc($p, $n, $f) {
    if (fwrite($f,$p, $n) < 1) throw new Exception("Unable to write");
//        while (--n >= 0) UPDATE_CRC(p[i++]);
}

function init_getbits() {
    global $bitbuf,$subbitbuf,$bitcount;
    $bitbuf = 0;
    $subbitbuf = 0;
    $bitcount = 0;
    fillbuf(BITBUFSIZ);
}


function make_table($nchar, $bitlen, $tablebits,$table) {
    global $right,$left;
    $count = array();
    $weight = array();
    $start = array();
    for($i = 0; $i <= 16; $i++) { $count[$i] = 0;}
    for($i = 0; $i < $nchar; $i++) { $count[$bitlen[$i]]++; }

    $start[1] = 0;
    for ($i = 1; $i <= 16; $i++) {
        $start[$i + 1] = ($start[$i] + ($count[$i] << (16 - $i))) & 0xFFFF;
    }

    if ($start[17] !== ((1 << 16) & 0xFFFF)) throw Exception("Bad table");

    $jutbits = 16 - $tablebits;
    for ($i = 1; $i <= $tablebits; $i++) {
        $start[$i] = logic_shift($start[$i],$jutbits);
        $weight[$i] = 1 << ($tablebits - $i);
    }
    while ($i <= 16) {
        $weight[$i] = 1 << (16 - $i);  $i++;
    }
    $i = logic_shift($start[$tablebits + 1], $jutbits);
    if ($i !== ((1 << 16) & 0xFFFF)) {
        $k = 1 << $tablebits;
        while ($i !== $k) $table[$i++] = 0;
    }

    $avail = $nchar;
    $mask = 1 << (15 - $tablebits);
    for ($ch = 0; $ch < $nchar; $ch++) {
        if (($len = $bitlen[$ch]) === 0) continue;
        $nextcode = $start[$len] + $weight[$len];
        if ($len <= $tablebits) {
            for ($i = $start[$len]; $i < $nextcode; $i++) $table[$i] = $ch;
        } else {
            $k = $start[$len];
            $p = &$table;
            $l = logic_shift($k, $jutbits);
            $i = $len - $tablebits;
            while ($i !== 0) {
                if ($p[$l] === 0) {
                    $right[$avail] = 0;
                    $left[$avail] = 0;
                    $p[$l] = $avail++;
                }
                if ($k & $mask){
                    $l = $p[$l];
                    $p = &$right;
                } else {
                    $l = $p[$l];
                    $p = &$left;
                }
                $k <<= 1;  $i--;
            }
            $p[$l] = $ch;
        }
        $start[$len] = $nextcode;
    }
    return $table;
}

function read_pt_len($nn, $nbit, $i_special) {
    global $pt_len,$pt_table,$bitbuf;
    $i = 0;
    $c = 0;
    $n = 0;
    $mask = 0;

    $n = getbits($nbit);
    if ($n === 0) {
        $c = getbits($nbit);
        for ($i = 0; $i < $nn; $i++) $pt_len[$i] = 0;
        for ($i = 0; $i < 256; $i++) $pt_table[$i] = $c;
    } else {
        $i = 0;
        while ($i < $n) {
            $c = logic_shift($bitbuf,BITBUFSIZ - 3);
            if ($c === 7) {
                $mask = 1 << (BITBUFSIZ - 1 - 3);
                while ($mask & $bitbuf) {
                    $mask = logic_shift($mask,1);
                    $c++;
                }
            }
            fillbuf(($c < 7) ? 3 : $c - 3);
            $pt_len[$i++] = $c;
            if ($i === $i_special) {
                $c = getbits(2);
                while (--$c >= 0) $pt_len[$i++] = 0;
            }
        }
        while ($i < $nn) $pt_len[$i++] = 0;
        $pt_table = make_table($nn, $pt_len, 8, $pt_table);
    }
}

function read_c_len() {
    global $left,$right,$bitbuf,$pt_len,$pt_table,$c_len,$c_table;
    $i = 0;
    $c = 0;
    $n = 0;
    $mask = 0;

    $n = getbits(CBIT);
    if ($n === 0) {
        $c = getbits(CBIT);
        for ($i = 0; $i < NC; $i++) $c_len[$i] = 0;
        for ($i = 0; $i < 4096; $i++) $c_table[$i] = $c;
    } else {
        $i = 0;
        while ($i < $n) {
            $c = $pt_table[logic_shift($bitbuf,BITBUFSIZ - 8)];
            if ($c >= NT) {
                $mask = 1 << (BITBUFSIZ - 1 - 8);
                do {
                    if ($bitbuf & $mask) $c = $right[$c];
                    else               $c = $left[$c];
                    $mask = logic_shift($mask,1);
                } while ($c >= NT);
            }
            fillbuf($pt_len[$c]);
            if ($c <= 2) {
                if      ($c === 0) $c = 1;
                else if ($c === 1) $c = getbits(4) + 3;
                else             $c = getbits(CBIT) + 20;
                while (--$c >= 0) $c_len[$i++] = 0;
            } else $c_len[$i++] = $c - 2;
        }
        while ($i < NC) $c_len[$i++] = 0;
        $c_table = make_table(NC, $c_len, 12, $c_table);
    }
}

function decode_c() {
    global $c_len,$bitbuf,$left,$right;
    Global $blocksize,$c_table;
    $j = 0;
    $mask = 0;
    if ($blocksize === 0) {
        $blocksize = getbits(16);
        read_pt_len(NT, TBIT, 3);
        read_c_len();
        read_pt_len(NP, PBIT, -1);
    }
    $blocksize--;
    $j = $c_table[logic_shift($bitbuf, BITBUFSIZ - 12)];
    if ($j >= NC) {
        $mask = 1 << (BITBUFSIZ - 1 - 12);
        do {
            if ($bitbuf & $mask) $j = $right[$j];
            else               $j = $left [$j];
            $mask = logic_shift($mask,1);
        } while ($j >= NC);
    }
    fillbuf($c_len[$j]);
    return $j;
}

function decode_p() {
    global $pt_table,$pt_len,$bitbuf,$right,$left;
    $j = $pt_table[logic_shift($bitbuf, BITBUFSIZ - 8)];
    if ($j >= NP) {
        $mask = 1 << (BITBUFSIZ - 1 - 8);
        do {
            if ($bitbuf & $mask) $j = $right[$j];
            else               $j = $left [$j];
            $mask = logic_shift($mask, 1);
        } while ($j >= NP);
    }
    fillbuf($pt_len[$j]);
    if ($j !== 0) $j = (1 << ($j - 1)) + getbits($j - 1);
    return $j;
}

function huf_decode_start(){
    init_getbits();
    $blocksize = 0;
}

function decode_start(){
    huf_decode_start();
    $j = 0;
}

function decode($count,$buffer){
    global $buffer,$j;
    static $i;
    $r = 0;
    $c = 0;
    while (--$j >= 0){
        $buffer[$r] = $buffer[$i];
        $i = ($i + 1) & (DICSIZ - 1);
        if (++$r === $count) return;
    }
    for ( ; ; ) {
        $c = decode_c();

        if ($c <= UCHAR_MAX) {
            $buffer[$r] = chr($c);
            if (++$r === $count) return;
        } else {
            $j = $c - (UCHAR_MAX + 1 - THRESHOLD);
            $i = ($r - decode_p() - 1) & (DICSIZ - 1);
            while (--$j >= 0) {
                $buffer[$r] = $buffer[$i];
                $i = ($i + 1) & (DICSIZ - 1);
                if (++$r === $count) return;
            }
        }
    }
}
