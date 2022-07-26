<?php
//declare(strict_types = 1);

const KILO_VERSION = "0.0.0";
const KILO_TAB_STOP = 8;
const KILO_QUIT_TIMES = 3;

class erow
{
    public int $idx;
    public int $size;
    public int $rsize;
    public string $chars;
    public string $render;
    public array $hl;
    public bool $hl_open_comment;
}

class editorConfig
{
    public int $cx;
    public int $cy;
    public int $rx;
    public int $rowoff;
    public int $coloff;
    public int $screenrows;
    public int $screencols;
    public int $numrows;
    public array $row;
    public int $dirty;
    public string $filename;
    public string $statusmsg;
    public int $statusmsg_time;
    public editorSyntax $syntax;

    public mixed $stdin;
    public int $quit_times;

    function __construct()
    {
        $this->stdin = fopen('php://stdin', 'r');    
        if ($this->stdin === false) die("fopen");
        $this->quit_times = KILO_QUIT_TIMES;
   
        $this->cx = 0;
        $this->cy = 0;
        $this->rx = 0;
        $this->rowoff = 0;
        $this->coloff = 0;
        $this->screenrows = 0;
        $this->screencols = 0;
        $this->numrows = 0;
        $this->row = [];
        $this->dirty = 0;
        $this->filename = "";
        $this->statusmsg = "\0";
        $this->statusmsg_time = 0;
        $this->syntax = new editorSyntax("NULL", [], [], "", "", "", 0);
    }
}
$E = new editorConfig();

// append buffer
class abuf
{
    public string $b;
    public int $len;

    function __construct()
    {
        $this->b = '';
        $this->len = 0;
    }
}

function abAppend(abuf $ab, string $s, int $len)
{
    $ab->b .= substr($s, 0, $len);
    $ab->len += $len;
}

function abFree(abuf $ab)
{
    $ab = null;
}

function CTRL_KEY(string $k): int
{
    return ord($k) & 0x1f;
}

const BACKSPACE     = 127;
const ARROW_LEFT    = 1000;
const ARROW_RIGHT   = 1001;
const ARROW_UP      = 1002;
const ARROW_DOWN    = 1003;
const DEL_KEY       = 1004;
const HOME_KEY      = 1005;
const END_KEY       = 1006;
const PAGE_UP       = 1007;
const PAGE_DOWN     = 1008;

// editorHighlight
const HL_NORMAL     = 0;
const HL_COMMENT    = 1;
const HL_MLCOMMENT  = 2;
const HL_KEYWORD1   = 3;
const HL_KEYWORD2   = 4;
const HL_STRING     = 5;
const HL_NUMBER     = 6;
const HL_MATCH      = 7;

const HL_HIGHLIGHT_NUMBERS = (1<<0);
const HL_HIGHLIGHT_STRINGS = (1<<1);

$C_HL_extensions = [".c", ".h", ".cpp", "NULL"];

$C_HL_keywords = [
    "switch", "if", "while", "for", "break", "continue", "return", "else",
    "struct", "union", "typedef", "static", "enum", "class", "case",
    "int|", "long|", "double|", "float|", "char|", "unsigned|", "signed|",
    "void|", "NULL"
];

/* PHP HighLight
 * copied from c rules
 * not yet completed
 */

$PHP_HL_extensions = [".php", "NULL"];

$PHP_HL_keywords = [
    "switch", "if", "while", "for", "break", "continue", "return", "else",
    "struct", "union", "typedef", "static", "enum", "class", "case",
    "int|", "long|", "double|", "float|", "char|", "unsigned|", "signed|",
    "void|", "NULL"
];

class editorSyntax
{
    public string $filetype;
    public array $filematch;
    public array $keywords;
    public string $singleline_comment_start;
    public string $multiline_comment_start;
    public string $multiline_comment_end;
    public int $flags;

    function __construct(
        string $type, 
        array $match, 
        array $keywords, 
        string $comment,
        string $mlstart,
        string $mlend, 
        int $flags)
    {
        $this->filetype = $type;
        $this->filematch = $match;
        $this->keywords = $keywords;
        $this->singleline_comment_start = $comment;
        $this->multiline_comment_start = $mlstart;
        $this->multiline_comment_end = $mlend;
        $this->flags = $flags;
    }
}

$HLDB[] = new editorSyntax(
    "c", 
    $C_HL_extensions,
    $C_HL_keywords, 
    "//", "/*", "*/",
    HL_HIGHLIGHT_NUMBERS | HL_HIGHLIGHT_STRINGS
);

$HLDB[] = new editorSyntax(
    "php", 
    $PHP_HL_extensions,
    $PHP_HL_keywords, 
    "//", "/*", "*/",
    HL_HIGHLIGHT_NUMBERS | HL_HIGHLIGHT_STRINGS
);

function HLDB_ENTRIES()
{
    global $HLDB;
    return count($HLDB);
}

function enableRawMode(): void
{
    global $E;
    if (!stream_set_blocking($E->stdin, false)) die("stream_set_blocking");
    
    exec('stty -echo -icanon');
    exec('stty -isig');     // disable CTRL-C, Z
    exec('stty -ixon');     // disable CTRL-S, Q
    exec('stty -iexten');   // disable CTRL-V, O
    exec('stty -icrnl');    // fix CTRL-M
    exec('stty -opost');
    exec('stty -brkint -inpck -istrip');    // disable misc
    exec('stty cs8');
  
    register_shutdown_function('disableRawMode');
}

function disableRawMode(): void
{
    fwrite(STDOUT, "\e[2J", 4);
    fwrite(STDOUT, "\e[H", 3);

    exec('stty sane');
}

function editorReadKey(): int
{
    global $E;

    $in = array($E->stdin);
    $out = $err = null;
    $seconds = 1;
    if (stream_select($in, $out, $err, $seconds) === false) die("stream select\n");

    $bytes = 1;
    $c = fread($E->stdin, $bytes);
    if ($c === false) die("fread");

    if (ord($c) === 0x1b) {
        $seq = [];
        if (stream_select($in, $out, $err, $seconds) === false) die("Unalbe to select on stdin\n");
        $seq[0] = fread($E->stdin, $bytes);

        $in2nd = array($E->stdin); // For missing array error for 2nd $in
        if (stream_select($in2nd, $out, $err, $seconds) === false) die("Unalbe to select on stdin\n");
        $seq[1] = fread($E->stdin, $bytes);

        if ($seq[0] === false || $seq[1] === false) return 0x1b;
        if ($seq[0] === '[') {
            if ((ord($seq[1]) >= ord('0')) && (ord($seq[1]) <= ord('9'))) {
                if (stream_select($in, $out, $err, $seconds) === false) 
                    die("Unalbe to selecton stdin\n");
                $seq[2] = fread($E->stdin, $bytes);
                if ($seq[2] === '~') {
                    switch ($seq[1]) {
                        case '1': return HOME_KEY;
                        case '3': return DEL_KEY;
                        case '4': return END_KEY;
                        case '5': return PAGE_UP;
                        case '6': return PAGE_DOWN;
                        case '7': return HOME_KEY;
                        case '8': return END_KEY;
                    }
                }                    
            } else {
                switch ($seq[1]) {
                    case 'A': return ARROW_UP;
                    case 'B': return ARROW_DOWN;
                    case 'C': return ARROW_RIGHT;
                    case 'D': return ARROW_LEFT;
                    case 'H': return HOME_KEY;
                    case 'F': return END_KEY;    
                }
            }
        } else if ($seq[0] === 'O') {
            switch ($seq[1]) {
                case 'H': return HOME_KEY;
                case 'F': return END_KEY;
            }
        }

        return 0x1b;        
    } else {
        return ord($c);
    }
}

function getWindowSize(int &$rows, int &$cols): int {
    if (exec('stty size', $output, $result) === false) {
        return -1;
    }
    $size = explode(' ', $output[0]);
    $rows = (int)$size[0];
    $cols = (int)$size[1];
    return 0;
}

function is_separator(string $c): bool
{
    return ctype_space($c) || $c === "\0" || str_contains(",.()+-/*=~%<>[];\"'", $c);
}

function editorUpdateSyntax(erow $row): void
{
    global $E;

    for ($i = 0; $i < $row->rsize; $i++) {
        $row->hl[$i] = HL_NORMAL;
    }

    if ($E->syntax->filetype === "NULL") return;

    $keywords = $E->syntax->keywords;

    $scs = $E->syntax->singleline_comment_start;
    $mcs = $E->syntax->multiline_comment_start;
    $mce = $E->syntax->multiline_comment_end;

    $scs_len = ($scs !== "") ? strlen($scs) : 0;
    $mcs_len = ($mcs !== "") ? strlen($mcs) : 0;
    $mce_len = ($mce !== "") ? strlen($mce) : 0;

    $prev_sep = true;
    $in_string = "";
    $in_comment = ($row->idx > 0 && $E->row[$row->idx - 1]->hl_open_comment);

    $i = 0;
    while ($i < $row->rsize) {
        $c = substr($row->render, $i, 1);
        $prev_hl = ($i > 0) ? $row->hl[$i - 1] : HL_NORMAL;

        if (($scs_len > 0) && ($in_string === "") && (!$in_comment)) {
            if (substr($row->render, $i, $scs_len) === $scs) {
                for ($j = $i; $j < $row->rsize - $i; $j++)
                    $row->hl[$j] = HL_COMMENT;
                break;
            }
        }

        if (($mcs_len > 0) && ($mce_len > 0) && ($in_string === "")) {
            if ($in_comment) {
                $row->hl[$i] = HL_MLCOMMENT;
                if (substr($row->render, $i, $mce_len) === $mce) {
                    for ($j = $i; $j < $i + $mce_len; $j++) {
                        $row->hl[$j] = HL_MLCOMMENT;
                    }
                    $i += $mce_len;
                    $in_comment = false;
                    $prev_sep = true;
                    continue;
                } else {
                    $i++;
                    continue;
                }
            } else if (substr($row->render, $i, $mcs_len) === $mcs) {
                for ($j = $i; $j < $i + $mcs_len; $j++) {
                    $row->hl[$j] = HL_MLCOMMENT;
                }
                $i += $mcs_len;
                $in_comment = true;
                continue;
            }
        }

        if ($E->syntax->flags & HL_HIGHLIGHT_STRINGS) {
            if ($in_string) {
                $row->hl[$i] = HL_STRING;
                if ($c === "\\" && $i + 1 < $row->rsize) {
                    $row->hl[$i + 1] = HL_STRING;
                    $i += 2;
                    continue;
                }
                if ($c === $in_string) $in_string = "";
                $i++;
                $prev_sep = true;
                continue;
            } else {
                if ($c === '"' || $c === "'") {
                    $in_string = $c;
                    $row->hl[$i] = HL_STRING;
                    $i++;
                    continue;
                }
            }
        }

        if ($E->syntax->flags & HL_HIGHLIGHT_NUMBERS) {
            if ((ctype_digit($c) && ($prev_sep || $prev_hl === HL_NUMBER)) ||
                ($c === "." && $prev_hl === HL_NUMBER)) {
                $row->hl[$i] = HL_NUMBER;
                $i++;
                $prev_sep = false;
                continue;
            }
        }

        if ($prev_sep) {
            for ($j = 0; $keywords[$j] !== "NULL"; $j++) {
                $klen = strlen($keywords[$j]);
                $kw2 = (substr($keywords[$j], -1) === "|");
                if ($kw2) $klen--;

                if ((substr($row->render, $i, $klen) === $keywords[$j]) &&
                    is_separator(substr($row->render, $i + $klen, 1))) {
                    for ($k = $i; $k < $i + $klen; $k++) {
                        $row->hl[$k] = $kw2 ? HL_KEYWORD2 : HL_KEYWORD1;
                    }
                    $i += $klen;
                    break;
                }
            }
            if ($keywords[$j] !== "NULL") {
                $prev_sep = false;
                continue;
            }
        }
        $prev_sep = is_separator($c);
        $i++;
    }

    $changed = ($row->hl_open_comment !== $in_comment);
    $row->hl_open_comment = $in_comment;
    if ($changed && ($row->idx + 1 < $E->numrows)) {
        editorUpdateSyntax($E->row[$row->idx + 1]);
    }
}

function editorSyntaxToColor(int $hl): int
{
    switch ($hl) {
        case HL_COMMENT:
        case HL_MLCOMMENT: return 36;
        case HL_STRING: return 35;
        case HL_NUMBER: return 31;
        case HL_MATCH: return 34;
        default: return 37;
    }
}

function editorSelectSyntaxHighlight(): void
{
    global $E;
    global $HLDB;

    $E->syntax->filetype = "NULL";
    $E->syntax->filematch = [];
    $E->syntax->flags = 0;

    if ($E->filename === "") return;

    $ext = strrchr($E->filename, '.');

    for ($j = 0; $j < HLDB_ENTRIES(); $j++) {
        $s = $HLDB[$j];
        $i = 0;
        while (!empty($s->filematch[$i])) {
            $is_ext = (substr($s->filematch[$i], 0, 1) === ".");
            if (($is_ext && $ext === $s->filematch[$i]) || 
                (!$is_ext && strstr($E->filename, $s->filematch[$i]) !== false)) {
                $E->syntax = $s;
                return;
            }
            $i++;
        }
    }
}

function editorRowCxToRx(erow $row, int $cx): int
{
    $rx = 0;
    for ($j = 0; $j < $cx; $j++) {
        if (substr($row->chars, $j, 1) === "\t") {
            $rx += (KILO_TAB_STOP - 1) - ($rx % KILO_TAB_STOP);
        } 
        $rx++;
    }
    return $rx;
}

function editorRowRxToCx(erow $row, int $rx): int 
{
    $cur_rx = 0;
    for ($cx = 0; $cx < $row->size; $cx++) {
        if (substr($row->chars, $cx, 1) === "\t") {
            $cur_rx += (KILO_TAB_STOP - 1) - ($cur_rx % KILO_TAB_STOP);
        }
        $cur_rx++;
        if ($cur_rx > $rx) return $cx;
    }
    return $cx;
}

function editorUpdateRow(erow $row) 
{
    $idx = 0;
    $row->render = "";
    for ($j = 0; $j < $row->size; $j++) {
        if (substr($row->chars, $j, 1) === "\t") {
            $idx++;
            $row->render .= " ";
            while ($idx % KILO_TAB_STOP !== 0) {
                $idx++;
                $row->render .= " ";
            }
        } else {
            $idx++;
            $row->render .= substr($row->chars, $j, 1);
        }
    }

    $row->render .= "\0";
    $row->rsize = strlen($row->render);

    editorUpdateSyntax($row);
}

function editorInsertRow(int $at, string $s, int $len): void
{
    global $E;

    if ($at < 0 || $at > $E->numrows) return;
    array_splice($E->row, $at, 0, "");
    for ($j = $at + 1; $j <= $E->numrows; $j++) {
        $E->row[$j]->idx++;
    }

    $E->row[$at] = new erow();

    $E->row[$at]->idx = $at;

    $E->row[$at]->size = $len;
    $E->row[$at]->chars = $s . "\0";
    $E->row[$at]->rsize = 0;
    $E->row[$at]->render = "";
    $E->row[$at]->hl = [];
    $E->row[$at]->hl_open_comment = false;
    editorUpdateRow($E->row[$at]);

    $E->numrows++;
    $E->dirty++;
}

function editorDelRow(int $at)
{
    global $E;

    if ($at < 0 || $at >= $E->numrows) return;
    array_splice($E->row, $at, 1);

    for ($j = $at; $j <= $E->numrows - 1; $j++) {
        $E->row[$j]->idx--;
    }

    $E->numrows--;
    $E->dirty++;
}

function editorRowInsertChar(erow $row, int $at, int $c): void 
{
    global $E;

    if ($at < 0 || $at > $row->size) $at = $row->size;
    $s = substr_replace($row->chars, chr($c), $at, 0); // 0 for inserting
    $row->chars = $s;
    $row->size++;
    editorUpdateRow($row);
    $E->dirty++;
}

function editorRowAppendString(erow $row, string $s, int $len): void
{
    global $E;

    $row->chars = rtrim($row->chars, "\0") . $s . "\0";
    $row->size += $len;
    editorUpdateRow($row);
    $E->dirty++;
}

function editorRowDelChar(erow $row, int $at): void
{
    global $E;

    if ($at < 0 || $at >= $row->size) return;
    $s = substr_replace($row->chars, "", $at, 0);
    $row->chars = $s;
    $row->size--;
    editorUpdateRow($row);
    $E->dirty++;
}

function editorInsertChar(int $c): void 
{
    global $E;

    if ($E->cy === $E->numrows) {
      editorInsertRow($E->numrows, "", 0);
    }
    editorRowInsertChar($E->row[$E->cy], $E->cx, $c);
    $E->cx++;
}

function editorInsertNewLine(): void
{
    global $E;

    if ($E->cx === 0) {
        editorInsertRow($E->cy, "", 0);
    } else {
        $row = $E->row[$E->cy];
        editorInsertRow($E->cy + 1, $row->chars[$E->cx], $row->size - $E->cx);
        $row = $E->row[$E->cy];
        $row->size = $E->cx;
        editorUpdateRow($row);
    }
    $E->cy++;
    $E->cx = 0;
}

function editorDelChar(): void 
{
    global $E;

    if ($E->cy === $E->numrows) return;
    if ($E->cx === 0 && $E->cy === 0) return;

    $row = $E->row[$E->cy];
    if ($E->cx > 0) {
        editorRowDelChar($row, $E->cx - 1);
        $E->cx--;
    } else {
        $E->cx = $E->row[$E->cy - 1]->size;
        editorRowAppendString($E->row[$E->cy - 1], $row->chars, $row->size);
        editorDelRow($E->cy);
        $E->cy--;
    }
}

function editorRowsToString(int &$buflen): string 
{
    global $E;

    $totlen = 0;
    for ($j = 0; $j < $E->numrows; $j++) {
        $totlen += $E->row[$j]->size + 1;   // 1 for "\n"
    }
    $buflen = $totlen;
    $buf = "";
    for ($j = 0; $j < $E->numrows; $j++) {
      $buf .= rtrim($E->row[$j]->chars, "\0");
      $buf .= "\n";
    }
    return $buf;
}

function editorOpen(string $filename): void 
{
    global $E;

    $fp = fopen($filename, 'r');
    if ($fp === false) die("fopen");
    $E->filename = $filename;

    editorSelectSyntaxHighlight();

    while ($line = fgets($fp)) {
        $line = rtrim($line);
        //$line .= "\0";
        editorInsertRow($E->numrows, $line, strlen($line));
    };

    $line = null;
    fclose($fp);
    $E->dirty = 0;
}

function editorSave(): void 
{
    global $E;
    if (is_null($E->filename) || $E->filename === "") {
        $E->filename = editorPrompt("Save as: %s (ESC to cancel)", "nullcall");
        if (is_null($E->filename) || $E->filename === "") {
            editorSetStatusMessage("Save aborted");
            return;
        }
        editorSelectSyntaxHighlight();
    }
    
    $len = 0;
    $buf = editorRowsToString($len);
    $fd = fopen($E->filename, 'w+');
    if ($fd !== false) {
        if (fwrite($fd, $buf, $len) === $len) {
            fclose($fd);
            $buf = null;
            $E->dirty = 0;
            editorSetStatusMessage("%d bytes written to disk", $len);
            return;
        }
    } 
    $buf = null;
    editorSetStatusMessage("Can't save! I/O error: len %d");
}

function editorFindCallback(string $query, int $key): void 
{
    global $E;

    static $last_match = -1;
    static $direction = 1;

    static $saved_hl_line;
    static $saved_hl = [];

    if (!empty($saved_hl)) {
        $E->row[$saved_hl_line]->hl = $saved_hl;
        $saved_hl = null;
    }

    if ($key === "\r" || $key === 0x1b) {
        $last_match = -1;
        $direction = 1;
        return;
    } else if ($key === ARROW_RIGHT || $key === ARROW_DOWN) {
        $direction = 1;
    } else if ($key === ARROW_LEFT || $key === ARROW_UP) {
        $direction = -1;
    } else {
        $last_match = -1;
        $direction = 1;
    }

    if ($last_match === -1) $direction = 1;

    $current = $last_match;
    for ($i = 0; $i < $E->numrows; $i++) {
        $current += $direction;
        if ($current === -1) $current = $E->numrows - 1;
        else if ($current === $E->numrows) $current = 0;

        $row = $E->row[$current];
        $match = strpos($row->render, $query);
        if ($match !== false) {
            $last_match = $current;
            $E->cy = $current;
            $E->cx = editorRowRxToCx($row, $match);
            $E->rowoff = $E->numrows;

            $saved_hl_line = $current;
            $saved_hl = $row->hl;
            for ($j = 0; $j < strlen($query); $j++) {
                $row->hl[$match + $j] = HL_MATCH;
            }
            break;
        }
    }
}

function editorFind(): void 
{
    global $E;
    $saved_cx = $E->cx;
    $saved_cy = $E->cy;
    $saved_coloff = $E->coloff;
    $saved_rowoff = $E->rowoff;

    $query = editorPrompt("Search: %s (Use ESC/Arrows/Enter)", "editorFindCallback");
    if ($query === "") {
        $E->cx = $saved_cx;
        $E->cy = $saved_cy;
        $E->colff = $saved_coloff;
        $E->rowoff = $saved_rowoff;
        return;
    } 

    for ($i = 0; $i < $E->numrows; $i++) {
        $row = $E->row[$i];
        $match = strpos($row->render, $query);
      if ($match !== false) {
            $E->cy = $i;
            $E->cx = editorRowRxToCx($row, $match);
            $E->rowoff = $E->numrows;
            break;
        }
    }
    $query = null;
}

function nullcall(): void
{
    // do nothing
    return;
}

  
function editorPrompt(string $prompt, callable $callback): string 
{
    $bufsize = 128;
    $buflen = 0;
    $buf = "";
    while (1) {
        editorSetStatusMessage($prompt, $buf);
        editorRefreshScreen();
        $c = editorReadKey();
        if ($c === DEL_KEY || $c === CTRL_KEY('h') || $c === BACKSPACE) {
            $buf = substr($buf, 0, -1);
        } else if ($c === 0x1b) {
            editorSetStatusMessage("");
            if ($callback !== "nullcall") $callback($buf, $c);
            return "";
        } else if ($c === ord("\r") || $c === ord("\n")) {
            if ($buflen != 0) {
                editorSetStatusMessage("");
                if ($callback !== "nullcall") $callback($buf, $c);
                return $buf;
            }
        } else if ( $c > 0x1f && $c < 128) { // control key is 0..0x1f
            $buf = rtrim($buf);
            $buf .= chr($c);
            $buflen++;
        }

        if ($callback !== "nullcall") $callback($buf, $c);
    }
}

function editorMoveCursor(int $key): void 
{
    global $E;
    $row = new erow;
    if ($E->cy >= $E->numrows) {
        $row = null;
    } else {
        $row = $E->row[$E->cy];
    }

    switch ($key) {
      case ARROW_LEFT:
        if ($E->cx !== 0) {
            $E->cx--;
        } else if ($E->cy > 0) {
            $E->cy--;
            $E->cx = $E->row[$E->cy]->size;
        }
        break;
      case ARROW_RIGHT:
        if (!is_null($row) && $E->cx < $row->size) {
            $E->cx++;
        } else if (!is_null($row) && $E->cx === $row->size) {
            $E->cy++;
            $E->cx = 0;
        }       
        break;
      case ARROW_UP:
        if ($E->cy !== 0) {
            $E->cy--;
        }
        break;
      case ARROW_DOWN:
        if ($E->cy < $E->numrows) {
            $E->cy++;
        }
        break;
    }

    if ($E->cy >= $E->numrows) {
        $row = null;
    } else {
        $row = $E->row[$E->cy];
    }
    if (!is_null($row)) {
        $rowlen = $row->size;
    } else {
        $rowlen = 0;
    }
    if ($E->cx > $rowlen) {
      $E->cx = $rowlen;
    }
}

function editorProcessKeypress(): void
{
    global $E;

    $c = editorReadKey();

    if ($c === 0) return;

    switch ($c) {
        case ord("\r"):
            editorInsertNewLine();
            break;
        case CTRL_KEY('q'):
            if ($E->dirty && $E->quit_times > 0) {
                editorSetStatusMessage("WARNING!!! File has unsaved changes. Press CTRL-Q %d more times to quit.", $E->quit_times);
                $E->quit_times--;
                return;
            }
            fwrite(STDOUT, "\e[2J", 4);
            fwrite(STDOUT, "\e[H", 3);
            exit(0);
            break;
        case CTRL_KEY('s'):
            editorSave();
            break;  
        case HOME_KEY:
            $E->cx = 0;
            break;
        case END_KEY:
            if ($E->cy < $E->numrows) {
                $E->cx = $E->row[$E->cy]->size;
            }
            break;
        case CTRL_KEY('f'):
            editorFind();
            break;
        case BACKSPACE:
        case CTRL_KEY('h'):
        case DEL_KEY:
            if ($c === DEL_KEY) editorMoveCursor(ARROW_RIGHT);
            editorDelChar();
            break;
        case PAGE_UP:
        case PAGE_DOWN:
            if ($c === PAGE_UP) {
                $E->cy = $E->rowoff;
            } else if ($c === PAGE_DOWN) {
                $E->cy = $E->rowoff + $E->screenrows - 1;
                if ($E->cy > $E->numrows) $E->cy = $E->numrows;
            }

            $times = $E->screenrows;
            while ($times--) {
                if ($c === PAGE_UP) {
                    editorMoveCursor(ARROW_UP);
                } else {
                    editorMoveCursor(ARROW_DOWN);
                }
            }
            break;
        case ARROW_UP:
        case ARROW_LEFT:
        case ARROW_DOWN:
        case ARROW_RIGHT:
            editorMoveCursor($c);
            break;
        case CTRL_KEY('l'):
        case 0x1b:
            break;
        default:
            editorInsertChar($c);
            break;
    }

    $E->quit_times = KILO_QUIT_TIMES;
}

function editorScroll(): void 
{
    global $E;

    $E->rx = 0;
    if ($E->cy < $E->numrows) {
        $E->rx = editorRowCxToRx($E->row[$E->cy], $E->cx);
    }

    if ($E->cy < $E->rowoff) {
        $E->rowoff = $E->cy;
    }
    if ($E->cy >= $E->rowoff + $E->screenrows) {
        $E->rowoff = $E->cy - $E->screenrows + 1;
    }
    if ($E->cx < $E->coloff) {
        $E->coloff = $E->rx;
    }
    if ($E->rx >= $E->coloff + $E->screencols) {
        $E->coloff = $E->rx - $E->screencols + 1;
    }
}

function iscntrl(string $c): bool
{
    return (ord($c) >= 0 && ord($c) <= 0x1f);
}

function editorDrawRows(abuf $ab) 
{
    global $E;
    for ($y = 0; $y < $E->screenrows; $y++) {
        $filerow = $y + $E->rowoff;
        if ($filerow >= $E->numrows) {
            if ($E->numrows === 0 && $y === (int)floor($E->screenrows / 3)) {
                $welcome = sprintf("Kilo editor -- version %s", KILO_VERSION);
                $welcomelen = strlen($welcome);
                if ($welcomelen > $E->screencols) $welcomelen = $E->screencols;
                $padding = (int)floor(($E->screencols - $welcomelen) / 2);
                if ($padding) {
                    abAppend($ab, "~", 1);
                    $padding--;
                }
                while ($padding--) abAppend($ab, " ", 1);
                abAppend($ab, $welcome, $welcomelen);
            } else {
                abAppend($ab, "~", 1);
            }
        } else {
            $len = $E->row[$filerow]->rsize - $E->coloff;
            if ($len < 0) $len = 0;
            if ($len > $E->screencols) $len = $E->screencols;
            $c = substr($E->row[$filerow]->render, $E->coloff, $len);
            $hl = $E->row[$filerow]->hl;
            $current_color = -1;
            for ($j = 0; $j < $len - 1; $j++) { 
                $c_j = substr($c, $j, 1);
                if (iscntrl($c_j)) {
                    $sym = (ord($c_j) <= 0x1f) ? chr(ord('@') + ord($c_j)) : '?';
                    abAppend($ab, "\e[7m", 4);
                    abAppend($ab, $sym, 1);
                    abAppend($ab, "\e[m", 3);
                    if ($current_color !== -1) {
                        $buf = sprintf("\e[%dm", $current_color);
                        $clen = strlen($buf);
                        abAppend($ab, $buf, $clen);
                    }
                } else if ($hl[$j] === HL_NORMAL) {
                    if ($current_color !== -1) {
                        abAppend($ab, "\e[39m", 5);
                        $current_color = -1;
                    }
                    abAppend($ab, $c_j, 1);
                } else {
                    $color = editorSyntaxToColor($hl[$j]);
                    if ($color !== $current_color) {
                        $current_color = $color;
                        $buf = sprintf("\e[%dm", $color);
                        abAppend($ab, $buf, strlen($buf));
                    }
                    abAppend($ab, $c_j, 1);
                }
            }
            abAppend($ab, "\e[39m", 5);
        }

        abAppend($ab, "\e[K", 3);
        abAppend($ab, "\r\n", 2);
    }
}

function editorDrawStatusBar(abuf $ab): void
{
    global $E;

    abAppend($ab, "\e[7m", 4);

    $status = sprintf("%.20s - %d lines %s", 
        $E->filename ? $E->filename : "[No Name]", $E->numrows,
        $E->dirty ? "(modified)" : "");
    $len = strlen($status);

    $rstatus = sprintf("%s | %d/%d", 
        $E->syntax->filetype !== "NULL" ? $E->syntax->filetype : "no ft",
        $E->cy + 1, 
        $E->numrows);
    $rlen = strlen($rstatus);

    if ($len > $E->screencols) $len = $E->screencols;
    abAppend($ab, $status, $len);
    while ($len < $E->screencols) {
        if (($E->screencols - $len) === $rlen) {
            abAppend($ab, $rstatus, $rlen);
            break;
        } else {
            abAppend($ab, " ", 1);
            $len++;
        }
    }
    abAppend($ab, "\e[m", 3);
    abAppend($ab, "\r\n", 2);
}

function editorDrawMessageBar(abuf $ab) 
{
    global $E;

    abAppend($ab, "\e[K", 3);
    $msglen = strlen($E->statusmsg);
    if ($msglen > $E->screencols) $msglen = $E->screencols;
    if ($msglen > 0 && (time() - $E->statusmsg_time < 5)) abAppend($ab, $E->statusmsg, $msglen);
  }


function editorRefreshScreen(): void
{
    global $E;

    editorScroll();

    $ab = new abuf();

    abAppend($ab, "\e[?25l", 6);
    abAppend($ab, "\e[H", 3);

    editorDrawRows($ab);
    editorDrawStatusBar($ab);
    editorDrawMessageBar($ab);

    $buf = sprintf("\e[%d;%dH", ($E->cy - $E->rowoff) + 1, ($E->rx - $E->coloff) + 1);
    abAppend($ab, $buf, strlen($buf));

    abAppend($ab, "\e[?25h", 6);
    fwrite(STDOUT, $ab->b, $ab->len);
    abFree($ab);
}

function editorSetStatusMessage(string $fmt, ...$arg): void 
{
    global $E;
    $E->statusmsg = sprintf($fmt, ...$arg);
    $E->statusmsg_time = time();
  }


function initEditor(): void 
{
    global $E;
    if (getWindowSize($E->screenrows, $E->screencols) == -1) die("getWindowSize");
    $E->screenrows -= 2;     
}

function main(): void 
{
    global $argc, $argv;

    enableRawMode();
    initEditor();
    if ($argc >= 2) {
        editorOpen($argv[1]);
    }

    editorSetStatusMessage("HELP: Ctrl-S = save | Ctrl-Q = quit | Ctrl-F = find");

    while (1) {
        editorRefreshScreen();
        editorProcessKeypress();
    }

    exit(0);
}

main();