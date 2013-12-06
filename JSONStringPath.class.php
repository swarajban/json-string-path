<?php

/**
 * Utility class intended to provide
 * XPath-like query support on string
 * representations of JSON encoded
 * objects
 */
class JSONStringPath {

	// ----------------- Member Variables ------------------ //
	private $rawJSONString = "";

	private $rawExpression = "";

	private $postfixString = "";
	// -----------------------------------------  //


	// ------------- Constants & Static Fields ------------- //
	private static $operators;

	const ARRAY_ACCESS_OPERATOR = '[';

	const JSON_TRUE_VALUE = 'true';
	const JSON_FALSE_VALUE = 'false';
	const JSON_NULL_VALUE = 'null';

	const DELIMITER_TYPE_BRACKET_ENUM = 1;
	const DELIMITER_TYPE_CURLY_BRACE_ENUM = 2;
	// ----------------------------------------------------- //

	/**
	 * Default constructor takes in JSON object still encoded as a string
	 *
	 * @param $string
	 */
	public function __construct($string){
		$this->rawJSONString = $string;
	}

	/**
	 * Primary interface for JSONStringPath that
	 * takes an expression and runs it on
	 * this JSON-encoded string
	 */
	public function runExpression($exp){
		$this->rawExpression = $exp;
		$this->postfixString = self::getPostfixString($this->rawExpression);
		return $this->evaluatePostfix($this->postfixString);
	}

	// ------------------------------------------------------------------------- //
	// *******************   High Level Internal Functions  ******************   //
	// ------------------------------------------------------------------------- //
	/**
	 * Evaluates a postfix expression
	 *
	 * Uses algorithm specified below:
	 * http://scriptasylum.com/tutorials/infix_postfix/algorithms/postfix-evaluation/index.htm
	 *
	 * @param $postfixExpression
	 * @return mixed
	 */
	private function evaluatePostfix($postfixExpression){
		$operandStack = array();
		while($postfixExpression){
			list($nextArgument, $postfixExpression) = self::getNextPostfixArgument($postfixExpression, $this->rawJSONString);
			if(self::isOperator($nextArgument)){
				$operator = self::getOperator($nextArgument);
				$params = array();
				for($i = 0; $i < $operator->numArgs; $i++){
					$params []= array_pop($operandStack);
				}
				$returnValue = $operator->evaluate(array_reverse($params));
				array_push($operandStack, $returnValue);
			}
			else{
				array_push($operandStack, $nextArgument);
			}
		}

		$stackLength = sizeof($operandStack);
		if($stackLength == 1){
			return array_pop($operandStack);
		}
		else{
			error_log("Operand stack length != 1 at end of postfix expression. Length: " . $stackLength);
			return '';
		}
	}
	// ------------------------------------------------------------------------- //
	// *****************   End High Level Internal Functions  ****************   //
	// ------------------------------------------------------------------------- //


	// ------------------------------------------------------------------------- //
	// ************************        Operators        ************************ //
	// ------------------------------------------------------------------------- //
	/**
	 * Returns an array of supported operators
	 *
	 * @return Operator[]
	 */
	private static function operators(){
		if(! self::$operators){
			self::$operators = array();

			$dotOperator = new Operator();
			$dotOperator->character = '.';
			$dotOperator->priority = 1;
			$dotOperator->numArgs = 2;
			$dotOperator->function = 'dotOperator';
			self::$operators []= $dotOperator;

			$arrayAccessOperator = new Operator();
			$arrayAccessOperator->character = '[';
			$arrayAccessOperator->priority = 1;
			$arrayAccessOperator->numArgs = 2;
			$arrayAccessOperator->function = 'arrayAccessOperator';
			self::$operators []= $arrayAccessOperator;

			$arrayLengthOperator = new Operator();
			$arrayLengthOperator->character = '#';
			$arrayLengthOperator->priority = 1;
			$arrayLengthOperator->numArgs = 1;
			$arrayLengthOperator->function = 'arrayLengthOperator';
			self::$operators []= $arrayLengthOperator;
		}

		return self::$operators;
	}

	/**
	 * Returns the Operator object for the given $operatorCharacter
	 *
	 * @param $operatorCharacter
	 * @return Operator
	 */
	private static function getOperator($operatorCharacter){
		$result = null;
		foreach(self::operators() as $operator){
			if($operator->character == $operatorCharacter){
				$result = $operator;
				break;
			}
		}
		return $result;
	}

	/**
	 * Returns true if the supplied argument is an operator
	 *
	 * @param $argument
	 * @return bool
	 */
	private static function isOperator($argument){
		return self::getOperator($argument) != null;
	}

	/**
	 * Compares two operators
	 *
	 * Returns 1 if first operator has greater
	 * precedence than the second, 0 if they are
	 * equal, and -1 if first operator has
	 * lesser precedence than the second
	 * @param $firstOp
	 * @param $secondOp
	 * @return int
	 */
	private static function compareOperators($firstOp, $secondOp){
		$firstOperatorOrder = self::getOperator($firstOp)->priority;
		$secondOperatorOrder = self::getOperator($secondOp)->priority;

		if($firstOperatorOrder > $secondOperatorOrder){
			return 1;
		}
		else if($firstOperatorOrder < $secondOperatorOrder){
			return -1;
		}
		else{ // $firstOperatorOrder == $secondOperatorOrder
			return 0;
		}
	}

	/**
	 * Perform the dot, '.', operation on $first and $second params
	 *
	 * @param $first
	 * @param $second
	 * @return stringp
	 */
	public static function dotOperator($first, $second){
		$result = $first;
		$first = self::removeSurroundingDelimiters($first, self::DELIMITER_TYPE_CURLY_BRACE_ENUM);
		$firstKeyPattern = '/^"(\w+)"/';
		$matches = array();
		while($first){
			preg_match($firstKeyPattern, $first, $matches);
			if(sizeof($matches) == 2){
				$key = $matches[1];
				$valueStartPos = strpos($first, ':') + 1;
				list($value, $rest) = self::getFirstValue($first, $valueStartPos);
				if($key == $second){
					$result = $value;
					break;
				}
				else{ // Remove $first Key and Value from $firstTrimmed
					$first = trim($rest, ',');
				}
			}
			else{
				error_log("Could not find first key in json string: " . $first);
				break;
			}
		}
		return $result;
	}

	/**
	 * Performs the array access operator where the
	 * first param is the array string and the second
	 * param is the element index
	 *
	 * @param $first
	 * @param $second
	 * @return string
	 */
	public static function arrayAccessOperator($first, $second){
		$value = '';
		$rest = self::removeSurroundingDelimiters($first, self::DELIMITER_TYPE_BRACKET_ENUM);
		$elementNumber = intval($second) + 1;
		while($elementNumber > 0){
			list($value, $rest) = self::getFirstValue($rest, 0);
			$rest = trim($rest, ',');
			$elementNumber--;
		}
		return $value;
	}

	/**
	 * Returns the length of the string representation of an array
	 *
	 * @param $arrayString
	 * @return int
	 */
	public static function arrayLengthOperator($arrayString){
		$length = 0;
		$elements = self::removeSurroundingDelimiters($arrayString, self::DELIMITER_TYPE_BRACKET_ENUM);
		while($elements){
			$length++;
			list($value, $elements) = self::getFirstValue($elements, 0);
			$elements = trim($elements, ',');
		}
		return $length;
	}
	// ------------------------------------------------------------------------- //
	// ************************      End Operators      ************************ //
	// ------------------------------------------------------------------------- //


	// ------------------------------------------------------------------------- //
	// ************************      Operator Utils     ************************ //
	// ------------------------------------------------------------------------- //
	/**
	 * Gets the value of the first key in the given JSON string
	 * Returns a list where the first element is the value
	 * and the second value is the rest of the string following
	 * the first value
	 *
	 * @param $string
	 * @param int
	 * @return array
	 */
	private static function getFirstValue($string, $valueStartPos){
		$rest = '';
		$value = '';
		// "key": true
		if(strpos($string, self::JSON_TRUE_VALUE) === $valueStartPos){
			$value = true;
			$rest = substr($string, $valueStartPos + strlen(self::JSON_TRUE_VALUE));
		}
		// "key": false
		elseif(strpos($string, self::JSON_FALSE_VALUE) === $valueStartPos){
			$value = false;
			$rest = substr($string, $valueStartPos + strlen(self::JSON_FALSE_VALUE));
		}
		// "key": null
		elseif(strpos($string, self::JSON_NULL_VALUE) === $valueStartPos){
			$value = null;
			$rest = substr($string, $valueStartPos + strlen(self::JSON_NULL_VALUE));
		}
		// "key": 12345
		elseif(ctype_digit($string{$valueStartPos}) || $string{$valueStartPos} == '-'){
			$numberEndPos = self::findNumberEndPos($string, $valueStartPos);
			$value = substr($string, $valueStartPos, $numberEndPos - $valueStartPos) + 0; // cast to number
			$rest = substr($string, $numberEndPos);
		}
		// "key": "string"
		elseif($string{$valueStartPos} == '"'){
			$valueStartPos += 1;
			$stringEndPos = strpos($string, '"', $valueStartPos);
			$value = substr($string, $valueStartPos, $stringEndPos - $valueStartPos);
			$rest = substr($string, $stringEndPos + 1);
		}
		// "key":{"object":4,"even":{"nestedOnes":true}}
		elseif($string{$valueStartPos} == '{'){
			list($value, $rest) = self::getValueBetweenDelimiters(substr($string, $valueStartPos), '{', '}');
		}
		// "key":["array","one","two",{"nestedKey":true}]
		elseif($string{$valueStartPos} == '['){
			list($value, $rest) = self::getValueBetweenDelimiters(substr($string, $valueStartPos), '[', ']');
		}
		return array($value, $rest);
	}

	/**
	 * Gets the string between given delimiters. Returns a list
	 * of two elements where the first element is the value between the
	 * given delimiters, and the second element is the remaining string.
	 * Used for extracting the string value for a JSON object or array
	 * that may have nested elements.
	 *
	 * For example, if the input string is '{"key": "value", "key2":{"innerKey": 5}},...'
	 * and the delimiters are '{' and '}', the function would return
	 * {"key": "value", "key2":{"innerKey": 5}} as the first value and the
	 * remaining string as the second value
	 *
	 * @param $string
	 * @param $startDelimiter
	 * @param $endDelimiter
	 * @return array
	 */
	private static function getValueBetweenDelimiters($string, $startDelimiter, $endDelimiter){
		$delimiterStack = array();
		array_push($delimiterStack, $startDelimiter);
		$currIndex = 1;
		while(sizeof($delimiterStack) != 0 && $currIndex < strlen($string)){
			$currChar = $string{$currIndex};
			if($currChar == $startDelimiter){
				array_push($delimiterStack, $startDelimiter);
			}
			else if($currChar == $endDelimiter){
				array_pop($delimiterStack);
			}
			$currIndex++;
		}
		$valueEndIndex = $currIndex;
		$value = substr($string, 0, $valueEndIndex);
		$rest = substr($string, $valueEndIndex);
		return array($value, $rest);

	}
	// ------------------------------------------------------------------------- //
	// ************************    End Operator Utils   ************************ //
	// ------------------------------------------------------------------------- //


	// ------------------------------------------------------------------------- //
	// ********************** Expression Parsing/Handling ********************** //
	// ------------------------------------------------------------------------- //
	/**
	 * Returns the postfix representation of input string
	 *
	 * Uses Shunting-yard algorithm described here:
	 * http://en.wikipedia.org/wiki/Shunting-yard_algorithm
	 *
	 * @param $infixExpression
	 * @return string
	 */
	private static function getPostfixString($infixExpression){
		$postfixString = "";
		$operatorStack = array();
		while(strlen($infixExpression) > 0){
			list($nextArgument, $infixExpression) = self::getNextInfixArgument($infixExpression);
			if(self::isOperator($nextArgument)){
				while(sizeof($operatorStack) > 0 &&
					self::compareOperators($nextArgument, self::topStack($operatorStack)) <= 0){
					$postfixString .= array_pop($operatorStack);
				}
				array_push($operatorStack, $nextArgument);
			}
			else{
				$postfixString .= '{' . $nextArgument . '}';
			}
		}
		while(sizeof($operatorStack) > 0){
			$postfixString .= array_pop($operatorStack);
		}
		return $postfixString;
	}

	/**
	 * Returns an array of two elements where the
	 * first value is the next infix argument, and
	 * the second value is the remaining infix expression
	 *
	 * @param $infixExpression
	 * @return array
	 */
	private static function getNextInfixArgument($infixExpression){
		$nextArgument = "";
		$currIndex = 0;
		if(self::isOperator($infixExpression{$currIndex})){
			$nextArgument = $infixExpression{$currIndex};
			$currIndex++;
			if($nextArgument == self::ARRAY_ACCESS_OPERATOR){
				// Removes the ending bracket for array access
				// Ex, Before: [1234].otherKey After: [123.otherKey
				$bracketPattern = '/^\[(\d+)](.*)/';
				$bracketReplacement = '$1$2';
				$rest = preg_replace($bracketPattern, $bracketReplacement, $infixExpression);
			}
			else{
				$rest = substr($infixExpression, $currIndex);
			}
		}
		else{
			while($currIndex < strlen($infixExpression) &&
				! self::isOperator($infixExpression{$currIndex})){
				$nextArgument .= $infixExpression{$currIndex};
				$currIndex++;
			}
			$rest = substr($infixExpression, $currIndex);
		}
		return array($nextArgument, $rest);
	}

	/**
	 * Returns an array of two elements where the
	 * first value is the next postfix argument, and
	 * the second value is the remaining postfix expression
	 *
	 * @param $postfixExpression
	 * @param $rootString
	 * @return array
	 */
	private static function getNextPostfixArgument($postfixExpression, $rootString){
		$firstChar = substr($postfixExpression, 0, 1);

		// Operator case
		if(self::isOperator($firstChar)){
			return array($firstChar, substr($postfixExpression, 1));
		}
		// Operand case e.g. {operand}.
		else{
			$matches = array();
			$firstOperandPattern = '/^\{([^}]+)\}(.*)/';
			preg_match($firstOperandPattern, $postfixExpression, $matches);
			if(sizeof($matches) > 2){
				$firstOperand = $matches[1];
				if($firstOperand == '$'){ // special case, $ is character for entire json string
					$firstOperand = $rootString;
				}
				return array($firstOperand, $matches[2]);
			}
			else{
				error_log("Could not match first operand in postfix expression: " . $postfixExpression);
				return array('', '');
			}
		}
	}
	// ------------------------------------------------------------------------- //
	// ******************** End Expression Parsing/Handling ******************** //
	// ------------------------------------------------------------------------- //


	// ------------------------------------------------------------------------- //
	// ************************  Generic Util Functions ************************ //
	// ------------------------------------------------------------------------- //
	/**
	 * Removes surrounding brackets from a string
	 *
	 * @param $string
	 * @param $delimiterType
	 * @return mixed
	 */
	private static function removeSurroundingDelimiters($string, $delimiterType){
		if($delimiterType == self::DELIMITER_TYPE_CURLY_BRACE_ENUM){
			$delimiterPattern = '/^\{(.*)\}$/'; // Matches everything withing curly braces: { ... }
		}
		else if ($delimiterType == self::DELIMITER_TYPE_BRACKET_ENUM){
			$delimiterPattern = '/^\[(.*)\]$/'; // Matches everything withing square brackets: [ ... ]
		}

		$removeBracketReplacement = '$1';
		return preg_replace($delimiterPattern, $removeBracketReplacement, $string);
	}

	/**
	 * Helper function to return the top element of an
	 * array being used as a stack
	 *
	 * @param $stack
	 * @return mixed
	 */
	private static function topStack($stack){
		return $stack[sizeof($stack) - 1];
	}

	/**
	 * Returns the last index of a string where $startPos
	 * is the first character of a number
	 *
	 * @param $string
	 * @param $startPos
	 * @return mixed
	 */
	private static function findNumberEndPos($string, $startPos=0){
		$currIndex = $startPos + 1;
		for($currIndex; $currIndex < strlen($string); $currIndex++){
			$currChar = $string{$currIndex};
			if(! (ctype_digit($currChar) || $currChar == '.')){
				break;
			}
		}
		return $currIndex;
	}
	// ------------------------------------------------------------------------- //
	// ************************    End Util Functions   ************************ //
	// ------------------------------------------------------------------------- //
}

/**
 * Class Operator
 *
 * Defines a simple Operator class to specify an operator's
 * character, priority, number of arguments, and function.
 * Used internally by JSONStringPath
 */
class Operator{
	public $character;
	public $priority;
	public $numArgs;
	public $function;

	/**
	 * Calls this operator's defined function with specified parameters
	 *
	 * @param array $parameters
	 * @return mixed
	 */
	public function evaluate(array $parameters){
		return call_user_func_array(array('JSONStringPath', $this->function), $parameters);
	}
}
