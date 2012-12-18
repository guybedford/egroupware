<?php
/**
 * eGroupWare - importexport
 * General Comma Serperated Values (CSV) record importer (abstract class)
 *
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package importexport
 * @link http://www.egroupware.org
 * @author Cornelius Weiss <nelius@cwtech.de>
 * @copyright Cornelius Weiss <nelius@cwtech.de>
 * @version $Id$
 */

/**
 * class import_csv
 * This a an abstract implementation of interface iface_import_record
 * An record is e.g. a single address or or single event.
 * No mater where the records come from, at the end the get_record method comes out
 * @todo Throw away spechial chars and trim() entries ?
 * @todo Check for XSS like userinput! (see common_functions)
 */
class importexport_import_csv implements importexport_iface_import_record { //, Iterator {

	const csv_max_linelength = 8000;
	
	/**
	 * @var array array with field mapping in form column number => new_field_name
	 */
	public $mapping = array();

	/**
	 * @var array with conversions to be done in form: new_field_name => conversion_string
	 */
	public $conversion = array();

	/**
	 * @var class with extra conversion functions
	 */
	public $conversion_class = null;

	/**
	 * @var array holding the current record
	 */
	protected $record = array();

	/**
	 * @var int current position counter
	 */
	protected $current_position = 0;

	/**
	 * @var int holds total number of records
	 */
	protected $num_of_records = 0;

	/**
	 * Cache for automatic conversion from human friendly
	 */	
	protected static $cf_parse_cache = array();

	/**
	 * @var stream
	 */
	private $resource;

	/**
	 * fieldseperator for csv file
	 * @access private
	 * @var char
	 */
	private $csv_fieldsep;
	
	/**
	 *
	 * @var string charset of csv file
	 */
	private $csv_charset;
	
	/**
	 * @param string $_resource resource containing data. May be each valid php-stream
	 * @param array $_options options for the resource array with keys: charset and fieldsep
	 */
	public function __construct( $_resource,  array $_options ) {
		$this->resource = $_resource;
		$this->csv_fieldsep = $_options['fieldsep'];
		if($_options['charset'] == 'user') $_options['charset'] = $GLOBALS['egw_info']['user']['preferences']['common']['csv_charset'];
		$this->csv_charset = $_options['charset'];
		return;
	} // end of member function __construct

	/**
	 * cleanup
	 */
	public function __destruct( ) {
	} // end of member function __destruct

	/**
	 * Returns array with the record found at position and updates the position
	 *
	 * @param mixed _position may be: {current|first|last|next|previous|somenumber}
	 * @return mixed array with data / false if no furtor records
	 */
	public function get_record( $_position = 'next' ) {
		
		if ($this->get_raw_record( $_position ) === false) {
			return false;
		}
		
		// skip empty records
		if( count( array_unique( $this->record ) ) < 2 ) return $this->get_record( $_position );
		
		if ( !empty( $this->conversion ) ) {
			$this->do_conversions();
		}
		
		if ( !empty( $this->mapping ) ) {
			$this->do_fieldmapping();
		}
		
		return $this->record;
	} // end of member function get_record
	
	/**
	 * Skips $_numToSkip of records from current position on
	 *
	 * @param int $_numToSkip
	 */
	public function skip_records( $_numToSkip ) {
		while ( (int)$_numToSkip-- !== 0 ) {
			fgetcsv( $this->resource, self::csv_max_linelength, $this->csv_fieldsep);
		}
	}
	
	/**
	 * updates $this->record
	 *
	 * @param mixed $_position
	 * @return bool
	 */
	private function get_raw_record( $_position = 'next' ) {
		switch ($_position) {
			case 'current' :
				if ($this->current_position == 0) {
					return;
				}
				break;
			case 'first' :
				if (!$this->current_position == 0) {
					$this->current_position = 0;
					rewind($this->resource);
				}
				
			case 'next' :
				$csv_data = fgetcsv( $this->resource, self::csv_max_linelength, $this->csv_fieldsep);
				if (!is_array($csv_data)) {
					return false;
				}
				$this->current_position++;
				$this->record = $GLOBALS['egw']->translation->convert($csv_data, $this->csv_charset);
				break;
				
			case 'previous' :
				if ($this->current_position < 2) {
					throw new Exception('Error: There is no previous record!');
				}
				$final_position = --$this->current_position;
				$this->current_position = 0;
				rewind($this->resource);
				while ($this->current_position !== $final_position) {
					$this->get_raw_record();
				}
				break;
				
			case 'last' :
				while ($this->get_raw_record()) {}
				break;
				
			default: //somenumber
				if (!is_int($_position)) {
					throw new Exception('Error: $position must be one of {current|first|last|next|previous} or an integer value');
				}
				if ($_position == $this->current_position) {
					break;
				}
				elseif ($_position < $this->current_position) {
					$this->current_position = 0;
					rewind($this->resource);
				}
				while ($this->current_position !== $_position) {
					$this->get_raw_record();
				}
				break;				
		}
		return;
	} // end of member function get_raw_record

	/**
	 * Retruns total number of records for the open resource.
	 *
	 * @return int
	 */
	public function get_num_of_records( ) {
		if ($this->num_of_records > 0) {
			return $this->num_of_records;
		}
		$current_position = $this->current_position;
		while ($this->get_raw_record()) {}
		$this->num_of_records = $this->current_position;
		$this->get_record($current_position);
		return $this->num_of_records;
	} // end of member function get_num_of_records

	/**
	 * Returns pointer of current position
	 *
	 * @return int
	 */
	public function get_current_position( ) {
		
		return $this->current_position;
		
	} // end of member function get_current_position


	/**
	 * does fieldmapping according to $this->mapping
	 *
	 * @return
	 */
	protected function do_fieldmapping( ) {
		$record = $this->record;
		$this->record = array();
		foreach ($this->mapping as $cvs_idx => $new_idx)
		{
			if( $new_idx == '' ) continue;
			$this->record[$new_idx] = $record[$cvs_idx];
		}
		return true;
	} // end of member function do_fieldmapping

	/**
	 * does conversions according to $this->conversion
	 *
	 * @return bool
	 */
	protected function do_conversions() {
		if ( $record = importexport_helper_functions::conversion( $this->record, $this->conversion, $this->conversion_class )) {
			$this->record = $record;
			return;
		}
		throw new Exception('Error: Could not applay conversions to record');
	} // end of member function do_conversions

	/**
	 * Automatic conversions from human values
	 *
	 * @param $fields Array of field type -> field name mappings
	 * @param $appname Appname for custom field parsing
	 * @param $selects Array of select values to be automatically parsed
	 * @param $format int 0 if records are supposed to be in DB format, 1 to treat as human values (Used for dates and select-cat)
	 *
	 * @return string warnings, if any
	 */
	public static function convert(Array &$record, Array $fields = array(), $appname = null, Array $selects = array(), $format=0) {
		$warnings = array();

		// Automatic conversions
		if($appname) {

			// Load translations
			translation::add_app($appname);

			if(!self::$cf_parse_cache[$appname]) {
				$c_fields = importexport_export_csv::convert_parse_custom_fields($appname, $selects, $links, $methods);
				self::$cf_parse_cache[$appname] = array($c_fields, $selects, $links, $methods);
			}
			list($c_fields, $c_selects, $links, $methods) = self::$cf_parse_cache[$appname];

			// Add in any fields that are keys to another app
			foreach((array)$fields['links'] as $link_field => $app)
			{
				if(is_numeric($link_field)) continue;
				$links[$link_field] = $app;
				// Set it as a normal link field
				$fields['links'][] = $link_field;
				unset($fields['links'][$link_field]);
			}

			// Not quite a recursive merge, since only one level
			foreach($fields as $type => &$list)
			{
				if($c_fields[$type]) {
					$list = array_merge($c_fields[$type], $list);
					unset($c_fields[$type]);
				}
			}
			$fields += $c_fields;
			$selects += $c_selects;
		}
		if($fields) {
			foreach((array)$fields['select'] as $name) {
				if($record[$name] != null && is_array($selects) && $selects[$name]) {
					$key = array_search(strtolower($record[$name]), array_map('strtolower',$selects[$name]));
					if($key !== false)
					{
						$record[$name] = $key;
					}
					else
					{
						$key = array_search(strtolower($record[$name]), array_map('strtolower',array_map('lang',$selects[$name])));
						if($key !== false) $record[$name] = $key;
					}
				}
			}
			foreach((array)$fields['links'] as $name) {
				if($record[$name] && $links[$name])
				{
					// Primary key to another app, not a link
					// Text - search for a matching record
					if(!is_numeric($record[$name]))
					{
						$results = egw_link::query($links[$name], $record[$name]);
						if(count($results) > 1)
						{
							// More than 1 result.  Check for exact match
							$exact_count = 0;
							foreach($results as $id => $title)
							{
								if($title == $record[$name])
								{
									$exact_count++;
									$app_id = $id;
								}
							}
							if($exact_count != 1)
							{
								$warnings[] = lang('Unable to link to %1 "%2"',
									lang($links[$name]), $record[$name]).
 									' - ' .lang('too many matches');
								$record[$name] = null;
								continue;
							}
							$record[$name] = $app_id;
						}
						else if (count($results) == 0)
						{
							$warnings[] = lang('Unable to link to %1 "%2"',
								lang($links[$name]), $record[$name]).
 								' - ' . lang('no matches');
							$record[$name] = null;
							continue;
						} else {
							$record[$name] = key($results);
						}
					}
				}
			}
			foreach((array)$fields['select-account'] as $name) {
				// Compare against null to deal with empty arrays
				if ($record[$name]) {
					// Automatically handle text owner without explicit translation
					$new_owner = importexport_helper_functions::account_name2id($record[$name]);
					if(count($new_owner) != count(explode(',',$record[$name])))
					{
						// Unable to parse value into account
						$warnings[] = lang('%1 is not a known user or group', $record[$name]);
					}
					if($new_owner != '') {
						$record[$name] = $new_owner;
					} else {
						// Clear it to prevent trouble later on
						$record[$name] = '';
					}
				}
			}
			foreach((array)$fields['select-bool'] as $name) {
				if($record[$name] != null && $record[$name] != '') {
					$record[$name] = ($record[$name] == lang('Yes') || $record[$name] == '1' ? 1 : 0);
				}
			}
			foreach((array)$fields['date-time'] as $name) {
				if (isset($record[$name]) && !is_numeric($record[$name]) && strlen(trim($record[$name])) > 0)
				{
					// Need to handle format first
					if($format == 1)
					{
						$formatted = egw_time::createFromFormat(
							'!'.egw_time::$user_dateformat . ' ' .egw_time::$user_timeformat,
							$record[$name],
							egw_time::$user_timezone
						);

						if(!$formatted && $errors = egw_time::getLastErrors())
						{
							// Try again, without time
							$formatted = egw_time::createFromFormat(
								'!'.egw_time::$user_dateformat,
								trim($record[$name]),
								egw_time::$user_timezone
							);
							
							if(!$formatted && $errors = egw_time::getLastErrors())
							{
								// Try again, anything goes
								try {
									$formatted = new egw_time($record[$name]);
								} catch (Exception $e) {
									$warnings[] = $name.': ' . $e->getMessage() . "\n" .
										'Format: '.'!'.egw_time::$user_dateformat . ' ' .egw_time::$user_timeformat;
									continue;
								}
								$errors = egw_time::getLastErrors();
								foreach($errors['errors'] as $char => $msg)
								{
									$warnings[] = "$name: [$char] $msg\n".
										'Format: '.'!'.egw_time::$user_dateformat . ' ' .egw_time::$user_timeformat;
								}
							}
						}
						if($formatted)
						{
							$record[$name] = $formatted->getTimestamp();
							// Timestamp is apparently in server time, but apps will do the same conversion
							$record[$name] = egw_time::server2user($record[$name],'ts');
						}
					}
					
					if(is_array(self::$cf_parse_cache[$appname][0]['date-time']) &&
							in_array($name, self::$cf_parse_cache[$appname][0]['date-time'])) {
						// Custom fields stored in a particular format (from customfields_widget)
						$record[$name] = date('Y-m-d H:i:s', $record[$name]);
					}
				}
				if(array_key_exists($name, $record) && strlen(trim($record[$name])) == 0)
				{
					$record[$name] = null;
				}
			}
			foreach((array)$fields['date'] as $name) {
				if (isset($record[$name]) && !is_numeric($record[$name]) && strlen(trim($record[$name])) > 0)
				{
					// Need to handle format first
					if($format == 1)
					{
						$formatted = egw_time::createFromFormat('!'.egw_time::$user_dateformat, $record[$name]);
						if($formatted && $errors = egw_time::getLastErrors() && $errors['error_count'] == 0)
						{
							$record[$name] = $formatted->getTimestamp();
						}
					}
					$record[$name] = egw_time::server2user($record[$name],'ts');
					if(is_array(self::$cf_parse_cache[$appname][0]['date']) &&
							in_array($name, self::$cf_parse_cache[$appname][0]['date'])) {
						// Custom fields stored in a particular format (from customfields_widget)
						$record[$name] = date('Y-m-d', $record[$name]);
					}
				}
				if(array_key_exists($name, $record) && strlen(trim($record[$name])) == 0)
				{
					$record[$name] = null;
				}
			}

			// Some custom methods for conversion
			foreach((array)$methods as $name => $method) {
				if($record[$name]) $record[$name] = ExecMethod($method, $record[$name]);
			}

			// cat_name2id will use currentapp to create new categories
			$current_app = $GLOBALS['egw_info']['flags']['currentapp'];
			if($appname) {
				$GLOBALS['egw_info']['flags']['currentapp'] = $appname;
			}
			foreach((array)$fields['select-cat'] as $name) {
				if($record[$name]) {
					// Only parse name if it needs it
					if($format == 1)
					{
						$cat_id = importexport_helper_functions::cat_name2id($record[$name]);
						// Don't clear it if it wasn't found
						if($cat_id) $record[$name] = $cat_id;
					}
				}
			}
			$GLOBALS['egw_info']['flags']['currentapp'] = $current_app;
		}

		return implode("\n",$warnings);
	}
} // end of import_csv
?>
