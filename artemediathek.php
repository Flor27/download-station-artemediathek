<?php

/**
 * @author Daniel Gehn <me@theinad.com>
 * @version 0.3a
 * @copyright 2015 Daniel Gehn
 * @license http://opensource.org/licenses/MIT Licensed under MIT License
 */
	require_once 'provider.php';
	
	class SynoFileHostingARTEMediathek extends TheiNaDProvider {
		
		protected $LogPath = '/tmp/arte-mediathek.log';
		
		protected $language = "de";
		protected $languageShortLibelle = "de";
		protected $altLanguageShortLibelle = "de";
		
		protected static $languageMap = array(
			'de'    => 'de',
			'fr'    => 'fr'
		);
		
		protected static $languageMapShortLibelle = array(
			'de'    => 'de',
			'fr'    => 'vf'
		);
		
		protected static $alternativeLangages = array(
			'de'	=> array('va','vo','vosta','ov'),
			'fr'	=> array('vf','vof','vostfr','vostf','vo','ov')
		);
		
		public function GetDownloadInfo() {
			$theOperaPlatform = false;
			$this->DebugLog("Determining language by url $this->Url");
			
			if(!preg_match('#http:\/\/(\w+\.)?arte.tv\/(?:guide\/)?([a-zA-Z]+)#si', $this->Url, $match)) {
				if(preg_match('#http:\/\/(\w+\.)?theoperaplatform.eu\/([a-zA-Z]+)\/#si', $this->Url, $match)) {
					$theOperaPlatform = true;
					// Quick'n'dirty...
					$match[1] = 'theOperaPlatform';
				}
			}
			
			if(isset($match[2]) && isset(self::$languageMap[$match[2]]))
			{
				$this->language = self::$languageMap[$match[2]];
				$this->languageShortLibelle = self::$languageMapShortLibelle[$match[2]];
			}
			
			$rawXML = $this->curlRequest($this->Url);
			$this->DebugLog("Getting download url");
			
			if($rawXML === null)
			{
				$this->DebugLog("not retrieved !?!");
				return false;
			}
			
			$notFound = true;
			
			if(isset($match[1]))
			{	
				switch($match[1])
				{
					case 'future.':
					case 'tracks.':
					$type = "dataFuture";
					$this->DebugLog("Found a Future site !");
					if(preg_match('#src=["|\']http.*?json_url=(.*?)%3F.*["|\']#si', $rawXML, $match) === 1)
					{
						$playerUrl = urldecode($match[1]);
						$this->DebugLog("the player is here : ".$playerUrl);
						$RawJSON = $this->curlRequest($playerUrl);
						if($RawJSON === null)
						{
							$this->DebugLog("Future site : not found or rawXML is null !");
							return false;
						}
						$data = json_decode($RawJSON);
						
						$notFound = false;
					}
					else {
						$this->DebugLog($match[1]." site : wtf is this HTML ?");
						return false;
					}
					break;
					
					case 'theOperaPlatform' :
					case 'concert.':
					case 'creative.':
					$type = "dataConcert";
					//$notFound
					$this->DebugLog("Found a Concert site !");
					if(preg_match('#arte_vp_url=["|\'](.*?)["|\']#si', $rawXML, $match) === 1)
					{
						$this->DebugLog("the player is here : ".$match[1]);
						$RawJSON = $this->curlRequest($match[1]);
						if($RawJSON === null)
						{
							$this->DebugLog($match[1]." site : not found or rawXML is null !");
							return false;
						}
						$data = json_decode($RawJSON);
						
						$notFound = false;
					}
					else {
						$this->DebugLog($match[1]." site : wtf is this HTML !?");
						
						$fp = fopen("/tmp/$type.txt",'w+');
						fwrite($fp,print_r($rawXML,1));
						fclose($fp);
						
						return false;
					}
					break;
					
					case 'www.':
					default:
					$type = "dataClassic";
					$this->DebugLog("Default Arte TV site !");
					if(preg_match('#data-embed-base-url=["|\'](.*?)["|\']#si', $rawXML, $match) === 1)
					{
						$this->DebugLog("Matched Embedded Base URL");
						
						$rawXML = $this->curlRequest($match[1]); 
						if($rawXML === null)
						{
							$this->DebugLog("Default site : not found or rawXML is null !");
							return false;
						}
						
						
						if (preg_match('#arte_vp_url=["|\'](.*?)["|\']#si', $rawXML, $match) === 1) {
							$vpUrl = $match[1];
						} else if(preg_match('#arte_vp_url_oembed=["|\'](.*?)["|\']#si', $rawXML, $match) === 1) {
							$vpUrl = $match[1];
						}
						
						if ($vpUrl != null) {
							if(preg_match('#https:\/\/api\.arte\.tv\/api\/player\/v1\/oembed\/[a-z]{2}\/([A-Za-z0-9-]+)(\?platform=.+)#si', $vpUrl, $match) === 1) {
								$id = $match[1];
								$fp = $match[2];
								
								$apiUrl = "https://api.arte.tv/api/player/v1/config/" . $this->language . "/" . $id . $fp;
								
								$RawJSON = $this->curlRequest($apiUrl);
								
								if ($RawJSON === null) {
									$this->DebugLog("API not found or null at $apiUrl !");
									return false;
								}
								
								$data = json_decode($RawJSON);
								
								$notFound = false;
								break;
							}
						}
					}
					else 
					{
						$notFound = true;
					}
					break;
				}
			}
			
			$this->DebugLog('Using language ' . $this->language .' and languageShortLibelle '.$this->languageShortLibelle);
			
			if($notFound === true || $data === null)
			{
				$this->DebugLog("not found or data is null !");
				return false;
			}
			
			$bestSource = array(
				'bitrate' => -1,
				'url' => '',
			);
			
			foreach ($data->videoJsonPlayer->VSR as $source) {
				$this->DebugLog("Found mediaType ".$source->mediaType ." and bitrate " . $source->bitrate);
				if ($source->mediaType == "mp4" && 
				((mb_strtolower($source->versionShortLibelle) == $this->languageShortLibelle)
				|| 
				in_array(mb_strtolower($source->versionShortLibelle), self::$alternativeLangages[$this->language]))
				&& $source->bitrate > $bestSource['bitrate'])
				{
					$bestSource['bitrate'] = $source->bitrate;
					$bestSource['url'] = $source->url;
				}
			}
			
			if ($bestSource['url'] !== '') {
				$filename = '';
				$url = trim($bestSource['url']);
				$pathinfo = pathinfo($url);
				
				$this->DebugLog("Title: " . $data->videoJsonPlayer->VTI . (isset($data->videoJsonPlayer->VSU) ? ' Subtitle: ' . $data->videoJsonPlayer->VSU : ''));
				$this->DebugLog("With bitrate " . $bestSource['bitrate']);
				if (!empty($data->videoJsonPlayer->VTI)) {
					$filename .= $data->videoJsonPlayer->VTI;
				}
				
				if (isset($data->videoJsonPlayer->VSU) && !empty($data->videoJsonPlayer->VSU)) {
					$filename .= ' - ' . $data->videoJsonPlayer->VSU;
				}
				
				
				if (empty($filename)) {
					$filename = $pathinfo['basename'];
				} else {
					$filename .= '.' . $pathinfo['extension'];
				}
				
				$this->DebugLog("Naming file: " . $filename);
				
				$DownloadInfo = array();
				$DownloadInfo[DOWNLOAD_URL] = $url;
				$DownloadInfo[DOWNLOAD_FILENAME] = $this->safeFilename($filename);
				
				return $DownloadInfo;
			}
			else
			{
				$this->DebugLog("Empty BestSource URL !!");
			}
			
			$this->DebugLog("Failed to determine best quality: " . print_r($data->videoJsonPlayer->VSR,1));
			
			return FALSE;
		}
	}

?>
