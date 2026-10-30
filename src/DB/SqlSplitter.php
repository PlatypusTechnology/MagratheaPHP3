<?php

namespace Magrathea\DB;

use Magrathea\Exceptions\MagratheaDBException;

#######################################################################################
####
####    MAGRATHEA PHP
####    v. 3.0
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    SqlSplitter created: 2026-10 by Paulo Martins
####
#######################################################################################

/**
* Splits SQL into statements, one at a time, reading it in chunks (a file is never loaded whole).
* It knows what the `mysql` client knows:
* - `'…'` and `"…"` with backslash escapes (and doubled quotes), `` `…` `` (a doubled backtick, no backslash escapes);
* - `-- ` and `#` line comments and `/*` block comments, which are removed;
* - `/*!` conditional comments and `/*+` optimizer hints, which are SQL and kept as they are
*   (the delimiter isn't looked for inside them);
* - `DELIMITER` lines (at the start of a statement), and CRLF line ends.
*
* Statements are yielded trimmed, without their delimiter. Empty ones are skipped.
*/
class SqlSplitter {

	const CHUNK_SIZE = 65536;
	/** first keywords of a read-only statement */
	const READ_ONLY_KEYWORDS = ["SELECT", "SHOW", "DESCRIBE", "DESC", "EXPLAIN"];

	const NORMAL = 0;
	const QUOTED = 1;
	const BACKTICK = 2;
	const LINE_COMMENT = 3;
	const BLOCK_COMMENT = 4;
	const KEPT_COMMENT = 5;

	private string $delimiter = ";";
	private int $state = self::NORMAL;
	private string $quote = "";
	/** unprocessed input (what's left of the last chunk, waiting for more) */
	private string $buffer = "";
	/** the current statement; "" until it has its first non-blank character */
	private string $statement = "";

	private function __construct() { }

	/**
	* Splits a string into its statements.
	* @param 	string 	$sql 	SQL, with any number of statements
	* @return 	string[] 		statements
	*/
	public static function Split(string $sql): array {
		return iterator_to_array(self::Statements([$sql]), false);
	}

	/**
	* Whether SQL is read-only: exactly one statement, read-only by `IsReadOnlyStatement()`.
	* The admin's Queries page and the CLI's `db:query` run anything else only once confirmed.
	* A guard against mistakes, not an access control: the function a `SELECT` calls can still write.
	* @param 	string 	$sql 	SQL, with any number of statements
	* @return 	bool 			false too when it can't be split (a `DELIMITER` line without a delimiter)
	*/
	public static function IsReadOnly(string $sql): bool {
		try {
			$statements = self::Split($sql);
		} catch(MagratheaDBException $ex) {
			return false;
		}
		return count($statements) === 1 && self::IsReadOnlyStatement($statements[0]);
	}

	/**
	* Whether one statement, as `Split()` gives it (comments removed), is read-only: it starts with `SELECT`, `SHOW`,
	* `DESCRIBE`, `DESC` or `EXPLAIN`, and has no `INTO OUTFILE` or `INTO DUMPFILE` (they write a file on the server).
	* Looked for anywhere, quoted text included: `SELECT 'into outfile'` is a write, which only asks for a confirmation.
	* @param 	string 	$statement 	one statement
	* @return 	bool
	*/
	public static function IsReadOnlyStatement(string $statement): bool {
		if(!preg_match('/^\s*([A-Za-z]+)/', $statement, $keyword)) return false;
		if(!in_array(strtoupper($keyword[1]), self::READ_ONLY_KEYWORDS, true)) return false;
		return !preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $statement);
	}

	/**
	* Streams a file's statements, reading it in chunks.
	* @param 	string 	$file 		path of the SQL file
	* @param 	int 	$chunkSize 	bytes read at a time
	* @return 	\Generator 		yields each statement (string)
	* @throws 	MagratheaDBException 	the file can't be read (when the generator starts)
	*/
	public static function FromFile(string $file, int $chunkSize = self::CHUNK_SIZE): \Generator {
		$handle = @fopen($file, "rb");
		if(!$handle) throw new MagratheaDBException("could not open SQL file [".$file."]");
		try {
			yield from self::Statements(self::ReadChunks($handle, max(1, $chunkSize)));
		} finally {
			fclose($handle);
		}
	}

	/**
	* Streams the statements of SQL given in pieces: a statement (or a quote, a comment, the delimiter)
	* can be split between two pieces.
	* @param 	iterable 	$chunks 	strings, in order
	* @return 	\Generator 		yields each statement (string)
	* @throws 	MagratheaDBException 	a `DELIMITER` line without a delimiter
	*/
	public static function Statements(iterable $chunks): \Generator {
		$splitter = new self();
		foreach($chunks as $chunk) {
			$splitter->buffer .= $chunk;
			yield from $splitter->Process(false);
		}
		yield from $splitter->Process(true);
		$last = trim($splitter->statement);
		if($last !== "") yield $last;
	}

	private static function ReadChunks($handle, int $chunkSize): \Generator {
		while(!feof($handle)) {
			$chunk = fread($handle, $chunkSize);
			if($chunk === false) throw new MagratheaDBException("could not read SQL file");
			if($chunk !== "") yield $chunk;
		}
	}

	/**
	* Consumes the buffer as far as it can. Without $eof, it stops where it needs more input to decide
	* (a `-` that may start `-- `, a `\` at the end, a possible delimiter or `DELIMITER` line…) and keeps the rest.
	* @param 	bool 	$eof 	no more input will come
	* @return 	string[] 	the statements it completed
	*/
	private function Process(bool $eof): array {
		$done = [];
		$buf = $this->buffer;
		$len = strlen($buf);
		$i = 0;
		while($i < $len) {
			if($this->state === self::NORMAL) {
				$c = $buf[$i];
				if($this->statement === "") {
					if(ctype_space($c)) { $i++; continue; }
					if($c === "d" || $c === "D") {
						if(!$eof && $len - $i < 10) break;
						if(strncasecmp(substr($buf, $i, 9), "delimiter", 9) === 0 && ($i + 9 >= $len || ctype_space($buf[$i + 9]))) {
							$newLine = strpos($buf, "\n", $i);
							if($newLine === false && !$eof) break;
							$lineEnd = $newLine === false ? $len : $newLine;
							$this->delimiter = $this->ParseDelimiter(substr($buf, $i + 9, $lineEnd - $i - 9));
							$i = $newLine === false ? $len : $newLine + 1;
							continue;
						}
					}
				}
				if($c === $this->delimiter[0]) {
					$size = strlen($this->delimiter);
					if(!$eof && $i + $size > $len) break;
					if(substr_compare($buf, $this->delimiter, $i, $size) === 0) {
						$statement = trim($this->statement);
						if($statement !== "") $done[] = $statement;
						$this->statement = "";
						$i += $size;
						continue;
					}
				}
				if($c === "'" || $c === '"') {
					$this->state = self::QUOTED;
					$this->quote = $c;
					$this->statement .= $c;
					$i++;
				} else if($c === "`") {
					$this->state = self::BACKTICK;
					$this->statement .= $c;
					$i++;
				} else if($c === "#") {
					$this->state = self::LINE_COMMENT;
					$i++;
				} else if($c === "-") {
					if(!$eof && $i + 3 > $len) break;
					// `--` starts a comment only when a space or a control character follows (or nothing)
					if(($buf[$i + 1] ?? "") === "-" && ($i + 2 >= $len || ord($buf[$i + 2]) <= 32)) {
						$this->state = self::LINE_COMMENT;
						$i += 2;
					} else {
						$this->statement .= $c;
						$i++;
					}
				} else if($c === "/") {
					if(!$eof && $i + 3 > $len) break;
					if(($buf[$i + 1] ?? "") === "*") {
						$kept = in_array($buf[$i + 2] ?? "", ["!", "+"], true);
						$this->state = $kept ? self::KEPT_COMMENT : self::BLOCK_COMMENT;
						if($kept) $this->statement .= "/*";
						$i += 2;
					} else {
						$this->statement .= $c;
						$i++;
					}
				} else {
					$run = strcspn($buf, "'\"`#-/".$this->delimiter[0], $i + 1) + 1;
					$this->statement .= substr($buf, $i, $run);
					$i += $run;
				}
			} else if($this->state === self::QUOTED) {
				$run = strcspn($buf, $this->quote."\\", $i);
				$this->statement .= substr($buf, $i, $run);
				$i += $run;
				if($i >= $len) break;
				if($buf[$i] === "\\") {
					if(!$eof && $i + 2 > $len) break;
					$this->statement .= substr($buf, $i, 2);
					$i += 2;
				} else {
					$this->statement .= $this->quote;
					$this->state = self::NORMAL;
					$i++;
				}
			} else if($this->state === self::BACKTICK) {
				// a doubled backtick closes and reopens: the same text either way
				$end = strpos($buf, "`", $i);
				if($end === false) {
					$this->statement .= substr($buf, $i);
					$i = $len;
					break;
				}
				$this->statement .= substr($buf, $i, $end + 1 - $i);
				$this->state = self::NORMAL;
				$i = $end + 1;
			} else if($this->state === self::LINE_COMMENT) {
				// the line break stays: it separates what's around the comment
				$newLine = strpos($buf, "\n", $i);
				if($newLine === false) { $i = $len; break; }
				$this->state = self::NORMAL;
				$i = $newLine;
			} else {
				$end = strpos($buf, "*/", $i);
				$kept = ($this->state === self::KEPT_COMMENT);
				if($end === false) {
					// a trailing `*` may be half of the `*/`
					$stop = $eof ? $len : $len - 1;
					if($kept) $this->statement .= substr($buf, $i, $stop - $i);
					$i = $stop;
					break;
				}
				if($kept) $this->statement .= substr($buf, $i, $end + 2 - $i);
				else if($this->statement !== "") $this->statement .= " ";
				$this->state = self::NORMAL;
				$i = $end + 2;
			}
		}
		$this->buffer = (string)substr($buf, $i);
		return $done;
	}

	/**
	* @param 	string 	$rest 	what follows `DELIMITER` on its line
	* @return 	string 	the new delimiter: its first word
	* @throws 	MagratheaDBException
	*/
	private function ParseDelimiter(string $rest): string {
		$words = preg_split('/\s+/', trim($rest), 2);
		$delimiter = $words[0] ?? "";
		if($delimiter === "" || str_contains($delimiter, "\\")) {
			throw new MagratheaDBException("DELIMITER must be followed by a delimiter (and it can't contain a backslash)");
		}
		return $delimiter;
	}

}
