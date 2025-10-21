<?php

require __DIR__.'/../lib/aws_2_8_8.phar';

use Aws\S3\S3Client;
use Aws\Common\Exception\MultipartUploadException;
use Aws\S3\Model\MultipartUpload\UploadBuilder;

class Amazon {
    
    //upload file(with AWS Signature Version 4.)
    static function s3uploadsignature($fn,$s3fn,$meta=[],$bucket=false,$region=false){
        $s3 = S3Client::factory([
            'signature'   => 'v4',
            'region' => $region?:Conf::$aws_region,
            'credentials' => [
                'key'    => Conf::$aws_key,
                'secret' => Conf::$aws_sec,
            ]
        ]);
        try{
            // image: png jpeg...,   don't detect svg file
            try{
                $image_info=getimagesize($fn);
                $mime = $image_info['mime'];
            }catch(Exception $e){
				// nothing
				elog($e, 'ERROR');
				elog($e->getMessage(), 'ERROR');
            }

            $params = [
                'Bucket'       => $bucket?:Conf::$aws_s3_bucket,
                'Key'          => $s3fn,
                'SourceFile'   => $fn,
                // 'ACL'          => 'public-read', // 非公開S3だと、ACL設定できない
                // 'ContentType'  => 'text/plain',
                'StorageClass' => 'REDUCED_REDUNDANCY',
                'Metadata'     => $meta
            ];
            // Add Image ContentType
            if(!empty($mime)) $params['ContentType'] = $mime;

            $result = $s3->putObject($params);
            return s3_url_to_cdn($bucket?:Conf::$aws_s3_bucket, $result['ObjectURL']);
        }catch(Exception $e){
            elog($e,"err");
            return false;
        }
        
    }

    //down file(with AWS Signature Version 4.)
    static function s3downloadsignature($bucket,$s3fn,$fn,$region=false){
        $s3 = S3Client::factory([
            'signature'   => 'v4',
            'region' => $region?:Conf::$aws_region,
            'credentials' => [
                'key'    => Conf::$aws_key,
                'secret' => Conf::$aws_sec,
            ]
        ]);
        $result = $s3->getObject(array(
            'Bucket' => $bucket,
            'Key'    => $s3fn,
            'SaveAs' => $fn
        ));
        return $result;
    }

    static function s3copysignature($bucket,$s3fn,$fn,$region=false){
        try {
            $s3 = S3Client::factory([
                'signature'   => 'v4',
                'region' => $region?:Conf::$aws_region,
                'credentials' => [
                    'key'    => Conf::$aws_key,
                    'secret' => Conf::$aws_sec,
                ]
            ]);
            $result = $s3->copyObject(array(
                'Bucket' => $bucket,
                // 'ACL' => 'public-read',
                'Key'    => $fn,
                'CopySource' => $bucket .$s3fn
            ));
            return s3_url_to_cdn($bucket?:Conf::$aws_s3_bucket, $result['ObjectURL']);
        }catch(Exception $e){
            elog($e,"err");
            return false;
        }
    }

    //upload file
    static function s3upload($fn,$s3fn,$meta=[],$bucket=false,$acl='public-read'){
        $s3 = S3Client::factory([
            'credentials' => [
                'key'    => Conf::$aws_key,
                'secret' => Conf::$aws_sec,
            ]
        ]);
        try{
            // image: png jpeg...,   don't detect svg file
            try{
                $image_info=getimagesize($fn);
                $mime = $image_info['mime'];
            }catch(Exception $e){
				// nothing
				elog($e, 'ERROR');
				elog($e->getMessage(), 'ERROR');
            }
            $s3fn = $encode ? md5($s3fn) : $s3fn;
            $params = [
                'Bucket'       => $bucket?:Conf::$aws_s3_bucket,
                'Key'          => $s3fn,
                'SourceFile'   => $fn,
                // 'ContentType'  => 'text/plain',
                'StorageClass' => 'REDUCED_REDUNDANCY',
                'Metadata'     => $meta
            ];
			if ($acl) $params['ACL'] = $acl;
            // Add Image ContentType
            if(!empty($mime)) $params['ContentType'] = $mime;

            $result = $s3->putObject($params);
            return s3_url_to_cdn($bucket?:Conf::$aws_s3_bucket, $result['ObjectURL']);
        }catch(Exception $e){
            elog($e,"err");
            return false;
        }    
    }

	//upload file
	static function s3readdir($dir,$meta=[],$bucket=false,$acl='public-read',$fields=['Key']){
        $s3 = S3Client::factory([
            'credentials' => [
                'key'    => Conf::$aws_key,
                'secret' => Conf::$aws_sec,
            ]
        ]);
        try{
            $res = $s3->listObjects([
				'Bucket' => $bucket?:Conf::$aws_s3_bucket,
				'Prefix' => $dir
			]);
			$result = [];
			foreach ($res['Contents'] as $r) {
				$c = [];
				foreach ($fields as $field) {
					$c[$field] = $r[$field];
				}
				$result[] = $c;
		  	}
			return $result;
        }catch(Exception $e){
            elog($e,"err");
            return false;
        }    
    }

    //upload file
    static function s3multipartupload($fn,$s3fn,$meta=[],$bucket=false){
        $bucket = $bucket?:Conf::$aws_s3_bucket;

        $client = S3Client::factory([
            'credentials' => [
                'key'    => Conf::$aws_key,
                'secret' => Conf::$aws_sec,
            ]
        ]);

        $key = $s3fn;
        $result = $client->createMultipartUpload(array(
        'Bucket' => $bucket,
        'Key'    => $key,
        ));
        $upload_id = $result['UploadId'];
        try{
     

            $file = fopen($fn, 'r');
            $parts = array();
            $part_number = 1;

            while (!feof($file)) {
                $result = $client->uploadPart(array(
                    'Bucket'     => $bucket,
                    'Key'        => $key,
                    'UploadId'   => $upload_id,
                    'PartNumber' => $part_number,
                    'Body'       => fread($file, 5 * 1024 * 1024),
                ));
                $parts[] = array(
                    'PartNumber' => $part_number++,
                    'ETag'       => $result['ETag'],
                );
            }

            $result = $client->completeMultipartUpload(array(
            'Bucket'   => $bucket,
            'Key'      => $key,
            'UploadId' => $upload_id,
            'Parts'    => $parts,
            ));
            $url = $result['Location'];
            fclose($file);
            

            return $url;
        }catch(Exception $e){
            elog($e,"err");
            return false;
        }
        
    }
    static function resetACL(&$obj=[],$bucket=false) {
        if(!Conf::$s3_cdn_enabled) return;

        if(empty(Conf::$s3_cdn_default_acl)) {
            //unset($obj['ACL']);
        } else {
            $obj['ACL'] = Conf::$s3_cdn_default_acl;
        }
    }
    //write data to file
    static function s3write($body,$s3fn,$bucket=false,$contentType=false){
        $s3 = S3Client::factory([
            'credentials' => [
                'key'    => Conf::$aws_key,
                'secret' => Conf::$aws_sec,
            ]
        ]);
        $obj = [
            'Bucket' => $bucket?:Conf::$aws_s3_bucket,
            'Key'    => $s3fn,
            'Body'   => $body,
            'ACL'    => 'public-read'
        ];
        Amazon::resetACL($obj,$bucket);
        if ($contentType) {
            $obj['ContentType'] = $contentType;
        }
        $result = $s3->putObject($obj);
        return s3_url_to_cdn($bucket?:Conf::$aws_s3_bucket, $result['ObjectURL']); // return $result['ObjectURL'];
    }

    static function s3uploadbase64($base64code, $s3fn, $bucket=false,$contentType=false){
        $s3 = S3Client::factory([
            'credentials' => [
                'key'    => Conf::$aws_key,
                'secret' => Conf::$aws_sec,
            ]
        ]);
        $obj = [
            'Bucket' => Conf::$aws_s3_bucket,
            'Key'    => $s3fn,
            'Body'   => base64_decode(end(explode(",", $base64code))),
            'ContentType'     => 'image/png',
            'ACL'    => 'public-read'
        ];
        Amazon::resetACL($obj,$bucket);
        if ($contentType) {
            $obj['ContentType'] = $contentType;
        }
        $result = $s3->putObject($obj);
        return s3_url_to_cdn($bucket?:Conf::$aws_s3_bucket, $result['ObjectURL']); // return $result['ObjectURL'];
    }
    
    static function s3ObjectExist($bucket,$s3fn){
        $s3 = S3Client::factory([
            'credentials' => [
                'key'    => Conf::$aws_key,
                'secret' => Conf::$aws_sec,
            ]
        ]);
        $result = $s3->doesObjectExist($bucket,$s3fn);
        return $result;
    }

    static function s3download($bucket,$s3fn,$fn){
        $s3 = S3Client::factory([
            'credentials' => [
                'key'    => Conf::$aws_key,
                'secret' => Conf::$aws_sec,
            ]
        ]);
        $result = $s3->getObject(array(
            'Bucket' => $bucket,
            'Key'    => $s3fn,
            'SaveAs' => $fn
        ));
        return $result;
    }

    /** get object from s3
     * https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-s3-2006-03-01.html#getobject
     * @param $bucket
     * @param $s3fn
     * @param $opts
     *  fn: save to local file
     *  SaveAs: save to local file
     *  acl: public-read|all, default is public-read
     *  headers: Obtain these parameters of the request header, If the file changes, s3 returns a 304 HTTP status code。
     *      ifMatch:             
     *      IfModifiedSince:     
     *      IfNoneMatch:         
     *      IfUnmodifiedSince:
     */
    static function s3GetObject($bucket, $s3fn, $opts=[]){
        $acl = isset($opts['acl'])?$opts['acl']:"public-read";
        $s3 = S3Client::factory([
            'credentials' => [
                'key'    => Conf::$aws_key,
                'secret' => Conf::$aws_sec,
            ]
        ]);

        if(Conf::$s3_get_check_acl) {
            // get object acl
            $res_acl = $s3->getObjectAcl(array(
                // Bucket is required
                'Bucket' => $bucket,
                // Key is required
                'Key' => $s3fn,
                // 'VersionId' => 'string',
                // 'RequestPayer' => 'string',
            ));
            // $res_acl['Grants']: 
            //         "Grants":[
            //             {
            //                 "Grantee": {
            //                     "ID": "8dec953600e774307a65eee8f872da69bd717f15b0b2daf22e909eb52aaed4fc",
            //                     "DisplayName": "admin"
            //                 },
            //                 "Permission": "FULL_CONTROL"
            //             },
            //             {
            //                 "Grantee": {
            //                     "URI": "http:\/\/acs.amazonaws.com\/groups\/global\/AllUsers"
            //                 },
            //                 "Permission": "READ"
            //             }
            //         ]

            // check read permission
            $canRead = false;
            if($acl=="public-read" || $acl!="all"){
                foreach($res_acl['Grants'] as $grant) {
                    $permission = $grant['Permission'];
                    $grantee = $grant['Grantee'];
                    // Everyone (public access permission)
                    if($grantee['URI']&&$grantee['URI']=="http://acs.amazonaws.com/groups/global/AllUsers"){
                        if($grant['Permission']=="READ"/*||$grant['Permission']=="READ_ACP"*/) {
                            $canRead = true;
                        }
                    }
                }
                if(!$canRead) {
                    elog("Amazon::s3GetObject_error_not_read: $bucket $s3fn");
                    return ["error_code"=>"404"];
                }
            }
        }

        // set object parameters
        $obj = array(
            'Bucket' => $bucket,
            'Key'    => $s3fn,
            // 'SaveAs' => $fn
        );
        // SaveAs
        $fn = $opts['fn']?: ($opts['SaveAs']?:false);
        if($fn) {
            $obj['SaveAs'] = $fn;
        }
        
        // set 'IfMatch', 'IfModifiedSince','IfNoneMatch','IfUnmodifiedSince'
        if(!empty($opts['headers'])){
            foreach($opts['headers'] as $h=>$v) {
                $h = str_replace("-","",strtolower($h));
                foreach(['IfMatch', 'IfModifiedSince','IfNoneMatch','IfUnmodifiedSince'] as $k){
                    if($h==strtolower($k)) {
                        $obj[$k] = $v;
                    }
                }
            }
        }
        $result = $s3->getObject($obj);
        // elog($result['data'], "========Metadata");
        return $result;
    }

    static function s3getObjectAcl($bucket,$s3fn,$fn){
        $s3 = S3Client::factory([
            'credentials' => [
                'key'    => Conf::$aws_key,
                'secret' => Conf::$aws_sec,
            ]
        ]);
        $result = $s3->getObjectAcl(array(
            // Bucket is required
            'Bucket' => $bucket,
            // Key is required
            'Key' => $s3fn,
            // 'VersionId' => 'string',
            // 'RequestPayer' => 'string',
        ));
        return $result;
    }



    static function s3copy($bucket,$s3fn,$fn){
        try {
            $s3 = S3Client::factory([
                'credentials' => [
                    'key'    => Conf::$aws_key,
                    'secret' => Conf::$aws_sec,
                ]
            ]);
            $obj = array(
                'Bucket' => $bucket,
                'ACL' => 'public-read',
                'Key'    => $fn,
                'CopySource' => $s3fn
            );
            Amazon::resetACL($obj,$bucket);
            $result = $s3->copyObject($obj);
            if($result&&!empty($result['ObjectURL'])){
                $result['ObjectURL'] = s3_url_to_cdn($bucket, $result['ObjectURL']);
            }
            return $result;
        }catch(Exception $e){
            elog($e,"err");
            return false;
        }
    }

    /**
     * 
     *
     * @param [type] $bucket
     * @param [type] $s3fns : array of filename or filename string
     * @return void
     */
    static function s3remove($bucket,$s3fns){
        $s3 = S3Client::factory([
            'credentials' => [
                'key'    => Conf::$aws_key,
                'secret' => Conf::$aws_sec,
            ]
        ]);
        if(is_string($s3fns))
            $s3fns = [$s3fns];
        $s3fns = array_map(function($e) use ($encoded){return ['Key'=> $encoded? md5($e):$e];},$s3fns);
        $result = $s3->deleteObjects([
            'Bucket'  => $bucket,
            'Objects' => $s3fns
        ]);
        return $result;
    }

    // TODO: Handle the file opening. If the file errors, we need to handle it.
    // TODO: What about if we have an excessive number of rows? Is it ok? Should we split up the CSV file? 
    // TODO: Write catch exception handler
    // Make sure to remove the test code before pushing to production!
    static function get_s3_edit_logs($bid, $date, $bucket=false, $region=false) {

        if (!$bid || !$date)
            return false;

        // Establish connection to S3
        $s3 = S3Client::factory([
            'signature'   => 'v4',
            'region' => $region ?: Conf::$aws_region, // Use this one instead.
            'credentials' => [
                'key'    => Conf::$aws_key,
                'secret' => Conf::$aws_sec,
            ]
        ]);
        $s3->registerStreamWrapper();
        
        // Amazon S3 bucket name
        $bucket = $bucket ?: 'edit-logs-' . Conf::$aws_s3_bucket;
        $file_key = "$date/edit_logs_$bid.csv"; // e.g. 20230110/edit_logs_903.csv

        // Check if file exists in S3.
        $file_exists = $s3->doesObjectExist($bucket, $file_key);
        if (!$file_exists) {
            elog("edit_logs: no log exists at $file_key");
            return false;
        }

        $results = [];
        try {
            // Open the file
            // Path breakdown: s3://edit-logs-{BUCKET_NAME}/{DATE}/edit_logs_{BID}.csv
            $url = "s3://$bucket/$file_key"; // e.g. s3://edit-logs-anybot-dv/20230110/edit_logs_903.csv
            
            $file = fopen($url, 'r');
            // TODO: Handle the file opening. If the file errors, we need to handle it.
            // TODO: What about if we have an excessive number of rows? Is it ok? Should we split up the CSV file? 
            $keys = fgetcsv($file);
            while (!feof($file)) {
                // The fgetcsv needs those specific params (',', '"', '"') or else it breaks and adds too many line breaks.
                $row = array_combine($keys, fgetcsv($file, 0, ',', '"', '"'));
                $results[] = $row;
            }
        } catch (Exception $err) {
            // TODO: The file probably doesn't exist. We will inform the user.
        }
        return $results;
    }

    static function generatePresignedUrl($key, $expiration = '+10 minutes', $bucket = false)
    {
        $s3 = S3Client::factory([
            'version' => 'latest',
            'region' => Conf::$aws_region,
            'credentials' => [
                'key' => Conf::$aws_key,
                'secret' => Conf::$aws_sec,
            ]
        ]);
        try {
            return $s3->getObjectUrl($bucket ?: Conf::$aws_s3_bucket, $key, $expiration);
        } catch (Exception $e) {
            elog($e, "err");
            return false;
        }
    }


}

