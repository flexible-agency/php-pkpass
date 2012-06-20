<?php
/**
 * PKPass - Creates iOS 6 passes
 * 
 * Author: Tom Schoffelen
 * Revision: Tom Janssen
 * 
 * www.tomttb.com
 */
class PKPass {
	#################################
	#######PROTECTED VARIABLES#######
	#################################
	
	/*
	 * Holds the path to the certificate
	 * Variable: string
	 */
	protected $certPath;
	
	/*
	 * Holds the files to include in the .pkpass
	 * Variable: array
	 */
	protected $files = array();
	
	/*
	 * Holds the json
	 * Variable: class
	 */
	protected $JSON;
	
	/*
	 * Holds the SHAs of the $files array
	 * Variable: array
	 */
	protected $SHAs;
	
	/*
	 * Holds the password to the certificate
	 * Variable: string
	 */
	protected $certPass = '';
	
	
	#################################
	########PRIVATE VARIABLES########
	#################################
	
	
	/*
	 * Holds the path to a temporary folder
	 */
	private $tempPath = '/tmp/'; // Must end with slash!
	
	/*
	 * Holds error info if an error occured
	 */
	private $sError = '';
	
	
	
	#################################
	#########PUBLIC FUNCTIONS########
	#################################
	
	
	
	
	public function __construct($certPath = false, $certPass = false, $JSON = false) {
		if($certPath != false) {
			$this->setCertificate($certPath);
		}
		if($certPass != false) {
			$this->setCertificatePassword($certPass);
		}
		if($JSON != false) {
			$this->setJSON($JSON);
		}
	}
	
	
	/*
	 * Sets the path to a certificate
	 * Parameter: string, path to certificate
	 * Return: boolean, true on succes, false if file doesn't exist
	 */
	public function setCertificate($path) {
		if(file_exists($path)) {
			$this->certPath = $path;
			return true;
		}
		
		$this->sError = 'Certificate file did not exist.';
		return false;
	}
	
	/*
	 * Sets the certificate's password
	 * Parameter: string, password to the certificate
	 * Return: boolean, always true
	 */
	public function setCertificatePassword($p) {
		$this->certPass = $p;
		return true;
	}
	
	/*
	 * Decodes JSON and saves it to a variable
	 * Parameter: json-string
	 * Return: boolean, true on succes, false if json wasn't decodable
	 */
	public function setJSON($JSON) {
		if(json_decode($JSON) !== false) {
			$this->JSON = $JSON;
			return true;
		}
		$this->sError = 'This is not a JSON string.';
		return false;
	}
	/*
	 * Adds file to the file array
	 * Parameter: string, path to file
	 * Return: boolean, true on succes, false if file doesn't exist
	 */
	public function addFile($path){
		if(file_exists($path)){
			$this->files[] = $path;
			return true;
		}
		$this->sError = 'File did not exist.';
		return false;
	}
	
	/*
	 * Creates the actual .pkpass file
	 * Parameter: boolean, if output is true, send the zip file to the browser.
	 * Return: zipped .pkpass file on succes, false on failure
	 */
	public function create($output = false) {
		$paths = $this->paths();
	
		//Creates and saves the json manifest
		$manifest = $this->createManifest();
		
		//Create signature
		if($this->createSignature($manifest) == false) {
			$this->clean();
			return false;
		}
		
		if($this->createZip($manifest) == false) {
			$this->clean();
			return false;
		}
		
		// Check if pass is created and valid
		if(!file_exists($this->tempPath.'pass.pkpass') || filesize($this->tempPath.'pass.pkpass') < 1){
			$this->sError = 'Error while creating pass.pkpass. Check your Zip extension.';
			$this->clean();
			return false;
		}

		// Output pass
		if($output == true) {
			header('Pragma: no-cache');
			header('Content-type: application/vnd-com.apple.pkpass');
			header('Content-length: '.filesize($paths['pkpass']));
			header('Content-Disposition: attachment; filename="'.basename($paths['pkpass']).'"');
			echo file_get_contents($paths['pkpass']);
			
			$this->clean();
		} else {
			$file = file_get_contents($paths['pkpass']);
			
			$this->clean();
			
			return $file;
		}
	}
	
	public function checkError(&$error) {
		if(trim($this->sError) == '') {
			return false;
		}
		
		$error = $this->sError;
		return true;
	}
	
	public function getError() {
		return $this->sError;
	}
	
	
	
	#################################
	#######PROTECTED FUNCTIONS#######
	#################################
	
	
	
	
	/*
	 * Subfunction of create()
	 * This function creates the hashes for the files and adds them into a json string.
	 */
	protected function createManifest() {
		// Creates SHA hashes for all files in package
		$this->SHAs['pass.json'] = sha1($this->JSON);
		foreach($this->files as $file) {
			$this->SHAs[basename($file)] = sha1(file_get_contents($file));
		}
		$manifest = json_encode((object)$this->SHAs);
		
		return $manifest;
	}
	
	
	/*
	 * Converts PKCS7 PEM to PKCS7 DER
	 * Parameter: string, holding PKCS7 PEM, binary, detached
	 * Return: string, PKCS7 DER
	 */
	protected function convertPEMtoDER($signature) {
	
//DO NOT MOVE THESE WITH TABS, OTHERWISE THE FUNCTION WON'T WORK ANYMORE!!
$begin = 'filename="smime.p7s"

';
$end = '

------';
		$signature = substr($signature, strpos($signature, $begin)+strlen($begin));    
		$signature = substr($signature, 0, strpos($signature, $end));
		$signature = base64_decode($signature);
		
		return $signature;
	}
	
	/*
	 * Creates a signature and saves it
	 * Parameter: json-string, manifest file
	 * Return: boolean, true on succes, failse on failure
	 */
	protected function createSignature($manifest) {
		$paths = $this->paths();
		
		file_put_contents($paths['manifest'], $manifest);
		
		$pkcs12 = file_get_contents($this->certPath);
		$certs = array();
		if(openssl_pkcs12_read($pkcs12, $certs, $this->certPass) == true) {
			$certdata = openssl_x509_read($certs['cert']);
			$privkey = openssl_pkey_get_private($certs['pkey'], $certPass );

			openssl_pkcs7_sign($paths['manifest'], $paths['signature'], $certdata, $privkey, array(), PKCS7_BINARY | PKCS7_DETACHED);
			
			$signature = file_get_contents($paths['signature']);
			$signature = $this->convertPEMtoDER($signature);
			file_put_contents($paths['signature'], $signature);
			
			return true;
		} else {
			$this->sError = 'Could not read the certificate';
			return false;
		}
	}
	
	/*
	 * Creates .pkpass (zip archive)
	 * Parameter: json-string, manifest file
	 * Return: boolean, true on succes, false on failure
	 */
	protected function createZip($manifest) {
		$paths = $this->paths();
		
		// Package file in Zip (as .pkpass)
		$zip = new ZipArchive();
		if(!$zip->open($paths['pkpass'], ZIPARCHIVE::CREATE)) {
			$this->sError = 'Could not open '.basename($paths['pkpass']).' with ZipArchive extension.';
			return false;
		}
		
		$zip->addFile($paths['signature'],'signature');
		$zip->addFromString('manifest.json',$manifest);
		$zip->addFromString('pass.json',$this->JSON);
		foreach($this->files as $file){
			$zip->addFile($file, basename($file));
		}
		$zip->close();
		
		return true;
	}
	
	/*
	 * Declares all paths used for temporary files.
	 */
	protected function paths() {
		//Declare base paths
		$paths = array(
						'pkpass' 	=> 'pass.pkpass',
						'signature' => 'signature',
						'manifest' 	=> 'manifest.json'
					  );
		
		//If trailing slash is missing, add it
		if(substr($this->tempPath, -1) != '/') {
			$this->tempPath = $this->tempPath.'/';
		}
		
		//Add temp folder path
		foreach($paths AS $pathName => $path) {
			$paths[$pathName] = $this->tempPath.$path;
		}
					  
		return $paths;
	}
	
	/*
	 * Removes all temporary files
	 */
	protected function clean() {
		$paths = $this->paths();
	
		foreach($paths AS $path) {
			if(file_exists($path)) {
				unlink($path);
			}
		}
		
		return true;
	}
}
