<?php
function getMetadata($meta_tag,$localFilePath) {
    //Check content to extract some meta-data
    $fp=fopen($localFilePath,'r');
    if($fp) {        
        $keys = ['ISA01','ISA02','ISA03','ISA04','ISA05','ISA06','ISA07','ISA08','ISA09','ISA10','ISA11','ISA12','ISA13','ISA14','ISA15','ISA16',
                 'GS01','GS02','GS03','GS04','GS05','GS06','GS07','GS08',
                 'ST01','ST02'];
        if(in_array($meta_tag,$keys)) {
            $hdr = fread($fp,256);
            $sep = $hdr[3];
            $del = $hdr[105];
            $segments =explode($del,$hdr);
            $metadata=[];
            foreach($segments as $k=>$line) {
                $elements = explode($sep,$line);
                $elements = array_map('trim',$elements); 
                for($id=1;$id<count($elements);$id++) {
                    $ele_id = sprintf('%s%02d',$elements[0],$id);
                    $metadata[$ele_id]=$elements[$id];
                }
            }
            return $metadata[$meta_tag];
        }else{
            $hdr = fread($fp,2048);
            $matches=[];
            if(preg_match("|<$meta_tag>([^<]*)</$meta_tag>|m",$hdr,$matches)) {
                return $matches[1];
            }
        }
    }
    return false;
}

assert(getMetadata('ShipToCode',__DIR__.'/CAT202221 856.xml')=='DQ');
assert(getMetadata('ISA08',__DIR__.'/856_D20200507T193946.edi')=='AMAZONDS');
assert(getMetadata('ISA12',__DIR__.'/856_D20200507T193946.edi')=='00401');
assert(getMetadata('GS01',__DIR__.'/856_D20200507T193946.edi')=='SH');
assert(getMetadata('GS02',__DIR__.'/856_D20200507T193946.edi')=='8177350119');