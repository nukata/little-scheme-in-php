<?php
// A little Scheme in PHP 7.1, v0.1 R02.03.15 by SUZUKI Hisao
declare(strict_types=1);
error_reporting(E_ALL);

// Cons Cell
final class Cell implements IteratorAggregate {
    public $car;                // Head part of the cell
    public $cdr;                // Tail part of the cell

    function __construct($car, $cdr) {
        $this->car = $car;
        $this->cdr = $cdr;
    }

    // Yield car, cadr, caddr and so on.
    function getIterator() {
        $j = $this;
        while ($j instanceof Cell) {
            yield $j->car;
            $j = $j->cdr;
        }
        if (! is_null($j))
            throw new ImproperListException($j);
    }

    function __toString() {
        return "({$this->car} . {$this->cdr})";
    }
}

// The last tail of the list is not null.
class ImproperListException extends Exception {
    public $tail;               // The last tail of the list

    function __construct($tail) {
        $this->tail = $tail;
    }
}

// ----------------------------------------------------------------------

// Value with its name
class NamedObject {
    private $name;

    function __construct(string $name) {
        $this->name = $name;
    }

    function __toString() {
        return $this->name;
    }
}

// A unique value which means the expression has no value
$NONE = new NamedObject ("#<VOID>");

// A unique value which means the End Of File
$EOF = new NamedObject ("#<EOF>");

// Scheme's symbol
final class Sym extends NamedObject {
    private static $Symbols = []; // Table of the interned symbols

    function __construct(string $name) {
        parent::__construct($name);
    }

    // Construct an interned symbol.
    static function New(string $name): Sym {
        if (array_key_exists($name, self::$Symbols)) {
            return self::$Symbols[$name];
        }
        $result = new Sym($name);
        self::$Symbols[$name] = $result;
        return $result;
    }
}

$QUOTE = Sym::New("quote");
$IF = Sym::New("if");
$BEGIN = Sym::New("begin");
$LAMBDA = Sym::New("lambda");
$DEFINE = Sym::New("define");
$SETQ = Sym::New("set!");
$APPLY = Sym::New("apply");
$CALLCC = Sym::New("call/cc");

// ----------------------------------------------------------------------

// Linked list of bindings which map symbols to values
final class Environment implements IteratorAggregate {
    public $symbol;          // Bound symbol
    public $value;           // Value mapped from the bound symbol
    public $next;            // Next binding

    function __construct(?Sym $symbol, $value, ?Environment $next) {
        $this->symbol = $symbol;
        $this->value = $value;
        $this->next = $next;
    }

    // Yield each binding.
    function getIterator() {
        $env = $this;
        while (! is_null($env)) {
            yield $env;
            $env = $env->next;
        }
    }

    // Search the bindings for a symbol.
    function lookFor(Sym $symbol): Environment {
        foreach ($this as $env)
            if ($env->symbol === $symbol) // compare the identities.
                return $env;
        throw new LogicException("'${symbol}' not found");
    }

    // Build a new environment by prepending the bindings of symbols and data
    // to the present environment.
    function prependDefs(?Cell $symbols, ?Cell $data): Environment {
        if (is_null($symbols)) {
            if (is_null($data)) {
                return $this;
            } else {
                $s = stringify($data);
                throw new InvalidArgumentException("surplus arg: ${s}");
            }
        } else {
            if (is_null($data)) {
                $s = stringify($symbols);
                throw new InvalidArgumentException("surplus param: ${s}");
            } else {
                $rest = $this->prependDefs($symbols->cdr, $data->cdr);
                return new Environment($symbols->car, $data->car, $rest);
            }
        }
    }

    // The global environment (to be defined later)
    public static $Global;

    function __toString() {
        $ss = [];
        foreach ($this as $e)
            if ($e === self::$Global) {
                $ss[] = "GlobalEnv";
                break;
            } else if (is_null($e->symbol)) { // frame marker
                $ss[] = "|";
            } else {
                $ss[] = (string) $e->symbol;
            }
        return "#<" . implode(" ", $ss) . ">";
    }
}

// ----------------------------------------------------------------------

// Operations in continuations

const OP_THEN = 'Then';
const OP_BEGIN = 'Begin';
const OP_DEFINE = 'Define';
const OP_SETQ = 'SetQ';
const OP_APPLY = 'Apply';
const OP_APPLYFUN = 'ApplyFun';
const OP_EVALARG = 'EvalArg';
const OP_CONSARGS = 'ConsArgs';
const OP_RESTOREENV = 'RestoreEnv';

// Scheme's continuation as a stack of steps
final class Continuation {
    private $stack;

    //Construct a copy of another continuation, or an empty continuation.
    function __construct(Continuation $other=NULL) {
        $this->stack = is_null($other) ? [] : $other->stack; // copy-on-write
    }

    // Copy steps from another continuation.
    function copyFrom(Continuation $other) {
        $this->stack = $other->stack; // copy-on-write
    }

    // Return true if the continuation is empty.
    function isEmpty(): bool {
        return ! $this->stack;
    }

    // Length of the continuation
    function count(): int {
        return count($this->stack);
    }

    // Return a quasi-stack trace.
    function __toString() {
        $ss = [];
        foreach ($this->stack as [$op, $value])
            $ss[] = $op . " ". stringify($value);
        return "#<" . implode("\n\t", $ss) . ">";
    }

    // Push a step to the top of the continuation.
    function push(string $operation, $value) {
        $this->stack[] = [$operation, $value];
    }

    // Pop a step, [operation, value], from the top of the continuation.
    function pop(): Array {
        return array_pop($this->stack);
    }

    // Push OP_RESTOREENV unless on a tail call.
    function pushRestoreEnv(Environment $env) {
        if ($this->stack) {
            $top = $this->stack[] = array_pop($this->stack);
            if ($top[0] === OP_RESTOREENV)
                return;         // tail call
        }
        $this->push(OP_RESTOREENV, $env);
    }
}

// ----------------------------------------------------------------------

// Lambda expression with its environment
class SchemeClosure {
    public $params;          // List of symbols as formal parameters
    public $body;            // List of expressions as a body
    public $env;             // Environment of the body

    function __construct(?Cell $parameters, Cell $body, Environment $env) {
        $this->params = $parameters;
        $this->body = $body;
        $this->env = $env;
    }

    function __toString() {
        return "#<" . stringify($this->params) . ":"
                    . stringify($this->body) . ":"
                    . stringify($this->env) . ">";
    }
}

// Built-in function
class Intrinsic {
    public $name;            // Function's name
    public $arity;           // Function's arity, -1 if it is variadic
    public $fun;             // Function's body

    function __construct(string $name, int $arity, callable $fun) {
        $this->name = $name;
        $this->arity = $arity;
        $this->fun = $fun;
    }

    function __toString() {
        return "#<{$this->name}:{$this->arity}>";
    }
}

// Exception thrown by `error` procedure of SRFI-23
class SchemeErrorException extends Exception {
    function __construct($reason, $arg) {
        parent::__construct("Error: " . stringify($reason, FALSE) .
                            ":" . stringify($arg));
    }
}

// ----------------------------------------------------------------------

// Convert an expression to a string.
function stringify($exp, bool $quote=TRUE): string {
    if ($exp === NULL) {
        return "()";
    } else if ($exp === FALSE) {
        return "#f";
    } else if ($exp === TRUE) {
        return "#t";
    } else if ($exp instanceof Cell) {
        $ss = [];
        try {
            foreach ($exp as $e)
                $ss[] = stringify($e, $quote);
        } catch (ImproperListException $ex) {
            $ss[] = ".";
            $ss[] = stringify($ex->tail, $quote);
        }
        return "(" . implode(" ", $ss) . ")";
    } else if (($exp instanceof string) && $quote) {
        return '"' . $exp . '"';
    } else if (is_float($exp)) { // 123.0 => "123.0"
        $s = (string) $exp;
        if ((string)((int) $exp) == $s)
            $s = sprintf("%.1f", $exp);
        return $s;
    }
    return "${exp}";
}

// ----------------------------------------------------------------------

function c(string $name, int $arity, callable $fun, ?Environment $next) {
    return new Environment(Sym::New($name),
                           new Intrinsic($name, $arity, $fun),
                           $next);
}

function globals(): ?Cell {
    $j = NULL;
    $env = Environment::$Global->next; // Skip the frame marker.
    foreach ($env as $e)
        $j = new Cell($e->symbol, $j);
    return $j;
}

Environment::$Global = new Environment(
    NULL,                       // frame marker
    NULL,
    c("car", 1, function($x) {
        return $x->car->car;
    }, c("cdr", 1, function($x) {
            return $x->car->cdr;
    }, c("cons", 2, function($x) {
        return new Cell($x->car, $x->cdr->car);
    }, c("eq?", 2, function($x) {
        return $x->car === $x->cdr->car;
    }, c("eqv?", 2, function($x) {
        $a = $x->car;
        $b = $x->cdr->car;
        if (is_numeric($a) && is_numeric($b)) {
            return $a == $b;
        } else {
            return $a === $b;
        }
    }, c("pair?", 1, function($x) {
        return $x->car instanceof Cell;
    }, c("null?", 1, function($x) {
        return is_null($x->car);
    }, c("not", 1, function($x) {
        return $x->car === FALSE;
    }, c("list", -1, function($x) {
        return $x;
    }, c("display", 1, function($x) {
        global $NONE;
        echo stringify($x->car, false);
        return $NONE;
    }, c("newline", 0, function($x) {
        global $NONE;
        echo PHP_EOL;
        return $NONE;
    }, c("read", 0, function($x) {
        return read_expression();
    }, c("eof-object?", 1, function($x) {
        global $EOF;
        return $x->car === $EOF;
    }, c("symbol?", 1, function($x) {
        return $x->car instanceof Sym;
    }, c("+", 2, function($x) {
        return $x->car + $x->cdr->car;
    }, c("-", 2, function($x) {
        return $x->car - $x->cdr->car;
    }, c("*", 2, function($x) {
        return $x->car * $x->cdr->car;
    }, c("<", 2, function($x) {
        return $x->car < $x->cdr->car;
    }, c("=", 2, function($x) {
        return $x->car == $x->cdr->car;
    }, c("error", 2, function($x) {
        throw new SchemeErrorException($x->car, $x->cdr->car);
    }, c("globals", 0, function($x) {
        return globals();
    }, new Environment(
        $CALLCC, $CALLCC,
        new Environment(
            $APPLY, $APPLY, NULL))))))))))))))))))))))));

// ----------------------------------------------------------------------

function evaluate($exp, Environment $env) {
    global $NONE, $QUOTE, $IF, $BEGIN, $LAMBDA, $DEFINE, $SETQ;
    $k = new Continuation();
    try {
        for (;;) {
            for (;;) {
                if ($exp instanceof Cell) {
                    $kar = $exp->car;
                    $kdr = $exp->cdr;
                    if ($kar === $QUOTE) { // (quote e)
                        $exp = $kdr->car;
                        break;
                    } else if ($kar === $IF) { // (if e1 e2 [e3])
                        $exp = $kdr->car;
                        $k->push(OP_THEN, $kdr->cdr);
                    } else if ($kar === $BEGIN) { // (begin e...)
                        $exp = $kdr->car;
                        if (! is_null($kdr->cdr))
                            $k->push(OP_BEGIN, $kdr->cdr);
                    } else if ($kar === $LAMBDA) { // (lambda (v...) e...)
                        $parameters = $kdr->car;
                        $body = $kdr->cdr;
                        $exp = new SchemeClosure($parameters, $body, $env);
                        break;
                    } else if ($kar === $DEFINE) { // (define v e)
                        $exp = $kdr->cdr->car;
                        $v = $kdr->car;
                        $k->push(OP_DEFINE, $v);
                    } else if ($kar === $SETQ) { // (set! v e)
                        $exp = $kdr->cdr->car;
                        $v = $kdr->car;
                        $k->push(OP_SETQ, $env->lookFor($v));
                    } else {    // (fun arg...)
                        $exp = $kar;
                        $k->push(OP_APPLY, $kdr);
                    }
                } else if ($exp instanceof Sym) {
                    $exp = $env->lookFor($exp)->value;
                    break;
                } else {        // a number, #t, #f etc.
                    break;
                }
            }
            for (;;) {
                # echo "_", $k->count();
                if ($k->isEmpty())
                    return $exp;
                [$op, $x] = $k->pop();
                if ($op === OP_THEN) { // $x is (e2 [3]).
                    if ($exp === FALSE) {
                        if (is_null($x->cdr)) {
                            $exp = $NONE;
                        } else {
                            $exp = $x->cdr->car; // e3
                            break;
                        }
                    } else {
                        $exp = $x->car; // e2
                        break;
                    }
                } else if ($op === OP_BEGIN) { // $x is (e...).
                    if (! is_null($x->cdr))
                        $k->push(OP_BEGIN, $x->cdr);
                    $exp = $x->car;
                    break;
                } else if ($op === OP_DEFINE) { // $x is a variable name.
                    assert(is_null($env->symbol)); // frame marker?
                    $env->next = new Environment($x, $exp, $env->next);
                    $exp = $NONE;
                } else if ($op === OP_SETQ) {   // $x is an Environment.
                    $x->value = $exp;
                    $exp = $NONE;
                } else if ($op === OP_APPLY) {
                    // $x is a list of args; $exp is a function.
                    if (is_null($x)) {
                        [$exp, $env] = apply_function($exp, NULL, $k, $env);
                    } else {
                        $k->push(OP_APPLYFUN, $exp);
                        while (! is_null($x->cdr)) {
                            $k->push(OP_EVALARG, $x->car);
                            $x = $x->cdr;
                        }
                        $exp = $x->car;
                        $k->push(OP_CONSARGS, NULL);
                        break;
                    }
                } else if ($op === OP_CONSARGS) {
                    // $x is a list of evaluated args (to be a cdr);
                    // $exp is a newly evaluated arg (to be a car).
                    $args = new Cell($exp, $x);
                    [$op, $exp] = $k->pop();
                    if ($op === OP_EVALARG) { // $exp is the next arg.
                        $k->push(OP_CONSARGS, $args);
                        break;
                    } else if ($op === OP_APPLYFUN) { // $exp is a function.
                        [$exp, $env] = apply_function($exp, $args, $k, $env);
                    } else {
                        throw new UnexpectedValueException("${op}; ${exp}");
                    }
                } else if ($op === OP_RESTOREENV) { // $x is an Environment.
                    $env = $x;
                } else {
                    throw new UnexpectedValueException("${op}, ${x}");
                }
            }
        }
    } catch (SchemeErrorException $ex) {
        throw $ex;
    } catch (Exception $ex) {
        $msg = get_class($ex) . ": " . $ex->getMessage();
        if (! $k->isEmpty())
            $msg .= "\n\t" . stringify($k);
        $ex = new Exception($msg, $ex->getCode(), $ex);
        throw $ex;
    }
}

// ----------------------------------------------------------------------

// Apply a function to arguments with a continuation and an environment.
function apply_function($fun, ?Cell $arg, Continuation $k, Environment $env) {
    global $NONE, $APPLY, $CALLCC;
    for (;;) {
        if ($fun === $CALLCC) {
            $k->pushRestoreEnv($env);
            $fun = $arg->car;
            $arg = new Cell(new Continuation($k), NULL);
        } else if ($fun === $APPLY) {
            $fun = $arg->car;
            $arg = $arg->cdr->car;
        } else {
            break;
        }
    }
    if ($fun instanceof Intrinsic) {
        if ($fun->arity >= 0) {
            if (is_null($arg) ? $fun->arity > 0 :
                iterator_count($arg) != $fun->arity)
                throw new InvalidArgumentException(
                    "arity not matched: " . stringify($fun) . " and " .
                    stringify($arg));
        }
        return [($fun->fun)($arg), $env];
    } else if ($fun instanceof SchemeClosure) {
        $k->pushRestoreEnv($env);
        $k->push(OP_BEGIN, $fun->body);
        return [$NONE,
                new Environment(NULL, // frame maker
                                NULL,
                                $fun->env->prependDefs($fun->params, $arg))];
    } else if ($fun instanceof Continuation) {
        $k->copyFrom($fun);
        return [$arg->car, $env];
    } else {
        throw new BadFunctionCallException(
            "not a function: " . stringify($fun) . " with " .
            stringify($arg));
    }
}

// ----------------------------------------------------------------------

// Split a string into an abstract sequence of tokens.
// For "(a 1)" it yields "(", "a", "1" and ")".
function split_string_into_tokens(string $source) {
    foreach (explode(PHP_EOL, $source) as $line) {
        $ss = [];               // to store string literals
        $x = [];
        $i = TRUE;
        foreach (explode('"', $line) as $e) {
            if ($i) {
                $x[] = $e;
            } else {
                $ss[] = '"' . $e; // Store a string literal.
                $x[] = "#s";
            }
            $i = ! $i;
        }
        $s = explode(";", implode(" ", $x))[0]; // Ignore "; ..."-comment.
        $s = str_replace("'", " ' ", $s);
        $s = str_replace("(", " ( ", $s);
        $s = str_replace(")", " ) ", $s);
        foreach (preg_split("/\s+/", $s) as $e) {
            if ($e == "#s")
                yield array_shift($ss);
            else if ($e != "")
                yield $e;
        }
    }
}

// Read an expression from tokens.
// Tokens will be left with the rest of the token strings if any.
function read_from_tokens(array &$tokens) {
    global $QUOTE;
    $token = array_shift($tokens);
    if (is_null($token))
        throw new OutOfBoundsException();
    switch ($token) {
    case "(":
        $z = new Cell(NULL, NULL);
        $y = $z;
        while ($tokens && $tokens[0] != ")") {
            if ($tokens[0] == ".") {
                array_shift($tokens);
                $y->cdr = read_from_tokens($tokens);
                if (tokens[0] != ")")
                    throw new LogicException(") is expected: " . $tokens[0]);
                break;
            }
            $e = read_from_tokens($tokens);
            $x = new Cell($e, NULL);
            $y->cdr = $x;
            $y = $x;
        }
        if (! $tokens)
            throw new OutOfBoundsException();
        array_shift($tokens);
        return $z->cdr;
    case ")":
        throw new LogicException("unexpected )");
    case "'":
        $e = read_from_tokens($tokens);
        return new Cell($QUOTE, new Cell($e, NULL)); // (quote e)
    case "#f":
        return FALSE;
    case "#t":
        return TRUE;
    }
    if ($token[0] == '"') {
        return substr($token, 1);
    } else if (is_numeric($token)) {
        return $token + 0;      // "1.0" -> float, "1" -> int
    } else {
        return Sym::New($token);
    }
}

// ----------------------------------------------------------------------

$STDIN_TOKENS = [];             // Tokens from the standard-in

// Read an expression from the console.
function read_expression(string $prompt1="", string $prompt2="") {
    global $EOF, $STDIN_TOKENS;
    for (;;) {
        $old = $STDIN_TOKENS;
        try {
            return read_from_tokens($STDIN_TOKENS);
        } catch (OutOfBoundsException $ex) {
            $line = readline($old ? $prompt2 : $prompt1);
            if (! is_string($line))
                return $EOF;
            $STDIN_TOKENS = $old;
            foreach (split_string_into_tokens($line) as $token)
                $STDIN_TOKENS[] = $token;
        } catch (Exception $ex) {
            $STDIN_TOKENS = []; // Discard erroneous tokens.
            throw $ex;
        }
    }
}

// Repeat Read-Eval-Print until End-Of-File.
function read_eval_print_loop() {
    global $NONE, $EOF;
    for (;;) {
        try {
            $exp = read_expression("> ", "| ");
            if ($exp === $EOF) {
                echo "Goodbye", PHP_EOL;
                return;
            }
            $result = evaluate($exp, Environment::$Global);
        } catch (Exception $ex) {
            echo $ex->getMessage(), PHP_EOL;
            continue;
        }
        if ($result !== $NONE)
            echo stringify($result), PHP_EOL;
    }
}

// Load a source code from a file.
function load(string $file_name) {
    $source = file_get_contents($file_name);
    $tokens = [];
    foreach (split_string_into_tokens($source) as $token)
        $tokens[] = $token;
    while ($tokens) {
        $exp = read_from_tokens($tokens);
        evaluate($exp, Environment::$Global);
    }
}

// ----------------------------------------------------------------------

if (basename(__FILE__) == $argv[0]) { // Main routine
    try {
        if (count($argv) > 1) {
            load($argv[1]);
            if (count($argv) < 3 || $argv[2] != "-")
                exit(0);
        }
        read_eval_print_loop();
        exit(0);
    } catch (Exception $ex) {
        $s = $ex->getMessage();
        fwrite(STDERR, ($s ? $s : $ex) . PHP_EOL);
        exit(1);
    }
}
