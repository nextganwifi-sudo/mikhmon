<?php
/*
 *  NextGan WiFi Solution — Offline + RouterOS DHCP Device Vendor, Model & Type Detector
 *  1. Inspects RouterOS /ip/dhcp-server/lease (host-name + class-id/vendor-class-id) & /ip/hotspot/host
 *  2. Matches offline IEEE MAC OUI Vendor database (+ optional 1.2s cached lookup for unknown public OUIs)
 *  3. Identifies exact Phone Brand, Device Model, & Device Type (Android Smartphone, iPhone/iPad, Windows PC, Mac, Router/AP, TV)
 */

if (isset($_SERVER["REQUEST_URI"]) && substr($_SERVER["REQUEST_URI"], -14) == "mac_vendor.php") {
    header("Location:./");
    exit;
}

/**
 * Normalize MAC address into uppercase AA:BB:CC:DD:EE:FF format.
 */
function ngwNormalizeMac($mac) {
    $hex = strtoupper(preg_replace('/[^0-9a-fA-F]/', '', (string)$mac));
    if (strlen($hex) !== 12) {
        return '';
    }
    return implode(':', str_split($hex, 2));
}

/**
 * Check locally administered / randomized MAC (2nd hex nibble is 2, 6, A, E).
 */
function ngwIsRandomizedMac($mac) {
    if (function_exists('isRandomizedMacAddress')) {
        return isRandomizedMacAddress($mac);
    }
    $norm = ngwNormalizeMac($mac);
    if (strlen($norm) < 2) {
        return false;
    }
    return (bool)preg_match('/^[0-9a-fA-F][26aAeE]/', substr($norm, 0, 2));
}

/**
 * Lookup manufacturer / vendor from built-in IEEE MAC OUI prefix database.
 */
function ngwLookupOfflineMacVendor($mac) {
    $hex = strtoupper(preg_replace('/[^0-9a-fA-F]/', '', (string)$mac));
    if (strlen($hex) < 6) {
        return '';
    }
    $oui = substr($hex, 0, 6);

    static $ouiMap = array(
        // Apple (iPhone / iPad / Mac)
        '000393'=>'Apple','000A95'=>'Apple','001124'=>'Apple','0016CB'=>'Apple','0017F2'=>'Apple',
        '001B63'=>'Apple','001CB3'=>'Apple','001E52'=>'Apple','001EC2'=>'Apple','001F5B'=>'Apple',
        '0021E9'=>'Apple','002241'=>'Apple','002312'=>'Apple','002332'=>'Apple','00236C'=>'Apple',
        '0023DF'=>'Apple','002436'=>'Apple','002500'=>'Apple','00254B'=>'Apple','0025BC'=>'Apple',
        '002608'=>'Apple','00264A'=>'Apple','0026B0'=>'Apple','0026BB'=>'Apple','040CCE'=>'Apple',
        '041552'=>'Apple','042665'=>'Apple','04489A'=>'Apple','045453'=>'Apple','0469F8'=>'Apple',
        '04D3CF'=>'Apple','04E536'=>'Apple','04F13E'=>'Apple','080007'=>'Apple','086698'=>'Apple',
        '087045'=>'Apple','0C3E9F'=>'Apple','0C4DE9'=>'Apple','0C771A'=>'Apple','1040F3'=>'Apple',
        '109ADD'=>'Apple','10DDB1'=>'Apple','14109F'=>'Apple','148FC6'=>'Apple','18AF61'=>'Apple',
        '18E7F4'=>'Apple','1CABA7'=>'Apple','2078F0'=>'Apple','20A2E4'=>'Apple','20C9D0'=>'Apple',
        '24A074'=>'Apple','24AB81'=>'Apple','283737'=>'Apple','28CFE9'=>'Apple','28E02C'=>'Apple',
        '28F076'=>'Apple','2C1F23'=>'Apple','2C3361'=>'Apple','2CBE08'=>'Apple','2CF0EE'=>'Apple',
        '3010E4'=>'Apple','3090AB'=>'Apple','34159E'=>'Apple','34363B'=>'Apple','34A395'=>'Apple',
        '380F4A'=>'Apple','38484C'=>'Apple','38B54D'=>'Apple','38C986'=>'Apple','3C0754'=>'Apple',
        '3C15C2'=>'Apple','3CE072'=>'Apple','403004'=>'Apple','40331A'=>'Apple','406C8F'=>'Apple',
        '40A6D9'=>'Apple','40B395'=>'Apple','40CBC0'=>'Apple','442A60'=>'Apple','444C0C'=>'Apple',
        '44D884'=>'Apple','48437C'=>'Apple','4860BC'=>'Apple','48746E'=>'Apple','48A195'=>'Apple',
        '48D705'=>'Apple','4C3275'=>'Apple','4C57CA'=>'Apple','4C7C5F'=>'Apple','4C8D79'=>'Apple',
        '50EAD6'=>'Apple','542696'=>'Apple','54724F'=>'Apple','54E43A'=>'Apple','581FAA'=>'Apple',
        '5855CA'=>'Apple','5C5948'=>'Apple','5C95AE'=>'Apple','5C969D'=>'Apple','5CF938'=>'Apple',
        '600308'=>'Apple','60334B'=>'Apple','606944'=>'Apple','609217'=>'Apple','60C547'=>'Apple',
        '60F81D'=>'Apple','60FACD'=>'Apple','64200C'=>'Apple','6476BA'=>'Apple','64A3CB'=>'Apple',
        '64B9E8'=>'Apple','64E682'=>'Apple','680927'=>'Apple','685B35'=>'Apple','68967B'=>'Apple',
        '689C70'=>'Apple','68A86D'=>'Apple','68AE20'=>'Apple','68D93C'=>'Apple','6C19C0'=>'Apple',
        '6C3E6D'=>'Apple','6C4008'=>'Apple','6C709F'=>'Apple','6C94F8'=>'Apple','701124'=>'Apple',
        '705681'=>'Apple','7073CB'=>'Apple','70CD60'=>'Apple','70DEE2'=>'Apple','70ECE4'=>'Apple',
        '741BB2'=>'Apple','748114'=>'Apple','74E1B6'=>'Apple','74E2F5'=>'Apple','7831C1'=>'Apple',
        '7867D7'=>'Apple','787E61'=>'Apple','78A3E4'=>'Apple','78CA39'=>'Apple','78D75F'=>'Apple',
        '78FD94'=>'Apple','7C11BE'=>'Apple','7C6D62'=>'Apple','7CC3A1'=>'Apple','7CD1C3'=>'Apple',
        '7CF05F'=>'Apple','80006E'=>'Apple','804971'=>'Apple','80929F'=>'Apple','80BE05'=>'Apple',
        '80E650'=>'Apple','80EA96'=>'Apple','842999'=>'Apple','843835'=>'Apple','84788B'=>'Apple',
        '848506'=>'Apple','8489AD'=>'Apple','84B153'=>'Apple','84FCFE'=>'Apple','881FA1'=>'Apple',
        '885395'=>'Apple','8863DF'=>'Apple','88C663'=>'Apple','8C006D'=>'Apple','8C2DAA'=>'Apple',
        '8C5877'=>'Apple','8C7C92'=>'Apple','8C8590'=>'Apple','8CFA42'=>'Apple','9027E4'=>'Apple',
        '907240'=>'Apple','90840D'=>'Apple','908D6C'=>'Apple','90B0ED'=>'Apple','90B21F'=>'Apple',
        '949426'=>'Apple','94F6A3'=>'Apple','9801A7'=>'Apple','9803D8'=>'Apple','985AEB'=>'Apple',
        '98B8E3'=>'Apple','98D6BB'=>'Apple','98E0D9'=>'Apple','98F0AB'=>'Apple','98FE94'=>'Apple',
        '9C04EB'=>'Apple','9C207B'=>'Apple','9C35EB'=>'Apple','9C4FDA'=>'Apple','9CF387'=>'Apple',
        'A0999B'=>'Apple','A0D795'=>'Apple','A43135'=>'Apple','A45E60'=>'Apple','A46706'=>'Apple',
        'A4B197'=>'Apple','A4C361'=>'Apple','A4D18C'=>'Apple','A4D1D2'=>'Apple','A82066'=>'Apple',
        'A85B78'=>'Apple','A8667F'=>'Apple','A886DD'=>'Apple','A88808'=>'Apple','A8968A'=>'Apple',
        'A8BBCF'=>'Apple','A8FAD8'=>'Apple','AC293A'=>'Apple','AC3C0B'=>'Apple','AC61EA'=>'Apple',
        'AC7F3E'=>'Apple','AC87A3'=>'Apple','ACBC32'=>'Apple','ACFDEC'=>'Apple','B03495'=>'Apple',
        'B065BD'=>'Apple','B09FBA'=>'Apple','B418D1'=>'Apple','B48B19'=>'Apple','B4F0AB'=>'Apple',
        'B8098A'=>'Apple','B817C2'=>'Apple','B844D9'=>'Apple','B853AC'=>'Apple','B8634D'=>'Apple',
        'B8782E'=>'Apple','B8C75D'=>'Apple','B8E856'=>'Apple','B8F6B1'=>'Apple','B8FF61'=>'Apple',
        'BC3BAF'=>'Apple','BC52B7'=>'Apple','BC6778'=>'Apple','BC926B'=>'Apple','BC9FEF'=>'Apple',
        'BCECA0'=>'Apple','C01ADA'=>'Apple','C06394'=>'Apple','C0847A'=>'Apple','C09F42'=>'Apple',
        'C0CCF8'=>'Apple','C0CECD'=>'Apple','C0D012'=>'Apple','C42C03'=>'Apple','C82A14'=>'Apple',
        'C8334B'=>'Apple','C869CD'=>'Apple','C86F1D'=>'Apple','C8B5B7'=>'Apple','C8BCC8'=>'Apple',
        'C8E0EB'=>'Apple','CC088D'=>'Apple','CC08E0'=>'Apple','CC20E8'=>'Apple','CC25EF'=>'Apple',
        'CC29F5'=>'Apple','CC4463'=>'Apple','CC785F'=>'Apple','D0034B'=>'Apple','D023DB'=>'Apple',
        'D02598'=>'Apple','D03311'=>'Apple','D04F7E'=>'Apple','D0817A'=>'Apple','D0A637'=>'Apple',
        'D0C5F3'=>'Apple','D0E140'=>'Apple','D4619D'=>'Apple','D49A20'=>'Apple','D4F46F'=>'Apple',
        'D8004D'=>'Apple','D81D72'=>'Apple','D83062'=>'Apple','D88F76'=>'Apple','D89695'=>'Apple',
        'D89E3F'=>'Apple','D8A25E'=>'Apple','D8BB2C'=>'Apple','D8CF9C'=>'Apple','DC2B2A'=>'Apple',
        'DC2B61'=>'Apple','DC3714'=>'Apple','DC415F'=>'Apple','DC56E7'=>'Apple','DC86D8'=>'Apple',
        'DC9B9C'=>'Apple','DCA4CA'=>'Apple','DCCB84'=>'Apple','E0338E'=>'Apple','E05F45'=>'Apple',
        'E06678'=>'Apple','E0ACCB'=>'Apple','E0B52D'=>'Apple','E0B9BA'=>'Apple','E0C767'=>'Apple',
        'E0C97A'=>'Apple','E0F5C6'=>'Apple','E0F847'=>'Apple','E425E7'=>'Apple','E48B7F'=>'Apple',
        'E498D6'=>'Apple','E4C63D'=>'Apple','E4CE8F'=>'Apple','E4E0A6'=>'Apple','E8040B'=>'Apple',
        'E80688'=>'Apple','E8802E'=>'Apple','E88D28'=>'Apple','EC3586'=>'Apple','EC852F'=>'Apple',
        'F01898'=>'Apple','F02475'=>'Apple','F07960'=>'Apple','F099BF'=>'Apple','F0B479'=>'Apple',
        'F0CBA1'=>'Apple','F0D1A9'=>'Apple','F0DBE2'=>'Apple','F0DCE2'=>'Apple','F0F61C'=>'Apple',
        'F40F24'=>'Apple','F41BA1'=>'Apple','F437B7'=>'Apple','F45C89'=>'Apple','F4F15A'=>'Apple',
        'F4F951'=>'Apple','F8037B'=>'Apple','F81EDF'=>'Apple','F82793'=>'Apple','F86214'=>'Apple',
        'F895EA'=>'Apple','FC253F'=>'Apple','FCE998'=>'Apple','FCFC48'=>'Apple',

        // Samsung
        '0000F0'=>'Samsung','000278'=>'Samsung','0007AB'=>'Samsung','000918'=>'Samsung','001247'=>'Samsung',
        '0012FB'=>'Samsung','001377'=>'Samsung','001599'=>'Samsung','0015B9'=>'Samsung','001632'=>'Samsung',
        '00166B'=>'Samsung','00166C'=>'Samsung','0016DB'=>'Samsung','0017C9'=>'Samsung','0017D5'=>'Samsung',
        '0018AF'=>'Samsung','001A8A'=>'Samsung','001B98'=>'Samsung','001C43'=>'Samsung','001D25'=>'Samsung',
        '001DF6'=>'Samsung','001E7D'=>'Samsung','001EE1'=>'Samsung','001EE2'=>'Samsung','002119'=>'Samsung',
        '00214C'=>'Samsung','0021D1'=>'Samsung','0021D2'=>'Samsung','002339'=>'Samsung','00233A'=>'Samsung',
        '002399'=>'Samsung','0023D6'=>'Samsung','0023D7'=>'Samsung','002454'=>'Samsung','002490'=>'Samsung',
        '002491'=>'Samsung','0024E9'=>'Samsung','002538'=>'Samsung','002566'=>'Samsung','002567'=>'Samsung',
        '002637'=>'Samsung','00265D'=>'Samsung','00265F'=>'Samsung','04180F'=>'Samsung','0808C2'=>'Samsung',
        '0821EF'=>'Samsung','08373D'=>'Samsung','08D42B'=>'Samsung','08EE8B'=>'Samsung','08FC88'=>'Samsung',
        '0C1420'=>'Samsung','0C715D'=>'Samsung','0C8910'=>'Samsung','0CB319'=>'Samsung','101DC0'=>'Samsung',
        '103047'=>'Samsung','1077B1'=>'Samsung','10A5D0'=>'Samsung','10D542'=>'Samsung','1449E0'=>'Samsung',
        '1489FD'=>'Samsung','14A364'=>'Samsung','14F42A'=>'Samsung','18227E'=>'Samsung','183A2D'=>'Samsung',
        '183F47'=>'Samsung','184617'=>'Samsung','1867B0'=>'Samsung','18E2C2'=>'Samsung','1C5A3E'=>'Samsung',
        '1C62B8'=>'Samsung','1C66AA'=>'Samsung','1CAF05'=>'Samsung','2013E0'=>'Samsung','205E34'=>'Samsung',
        '206432'=>'Samsung','206E9C'=>'Samsung','20D390'=>'Samsung','244B03'=>'Samsung','24C696'=>'Samsung',
        '24DBED'=>'Samsung','2827BF'=>'Samsung','28395E'=>'Samsung','288335'=>'Samsung','28BAB5'=>'Samsung',
        '28CC01'=>'Samsung','2C4401'=>'Samsung','2CAE2B'=>'Samsung','30074D'=>'Samsung','301966'=>'Samsung',
        '3096FB'=>'Samsung','30C7AE'=>'Samsung','30D6C9'=>'Samsung','34145F'=>'Samsung','3423BA'=>'Samsung',
        '343111'=>'Samsung','34AA8B'=>'Samsung','34BE00'=>'Samsung','34C3AC'=>'Samsung','380A94'=>'Samsung',
        '380B40'=>'Samsung','3816D1'=>'Samsung','382DE8'=>'Samsung','389496'=>'Samsung','38AA3C'=>'Samsung',
        '38ECE4'=>'Samsung','3C5A37'=>'Samsung','3C6200'=>'Samsung','3C8BFE'=>'Samsung','3CA10D'=>'Samsung',
        '3CBBFD'=>'Samsung','400E85'=>'Samsung','444E1A'=>'Samsung','446D57'=>'Samsung','44783E'=>'Samsung',
        '4844F7'=>'Samsung','4C3C16'=>'Samsung','4CBC98'=>'Samsung','5001BB'=>'Samsung','503275'=>'Samsung',
        '505527'=>'Samsung','508569'=>'Samsung','50A4C8'=>'Samsung','50B7C3'=>'Samsung','50C8E5'=>'Samsung',
        '50F0D3'=>'Samsung','5440AD'=>'Samsung','54880E'=>'Samsung','5492BE'=>'Samsung','549B12'=>'Samsung',
        '58C38B'=>'Samsung','5C0A5B'=>'Samsung','5C2E59'=>'Samsung','5C3C27'=>'Samsung','5CA39D'=>'Samsung',
        '5CE8EB'=>'Samsung','5CF6DC'=>'Samsung','606BBD'=>'Samsung','60A10A'=>'Samsung','60AF6D'=>'Samsung',
        '60D0A9'=>'Samsung','646CB2'=>'Samsung','647791'=>'Samsung','64B310'=>'Samsung','684898'=>'Samsung',
        '68EBAE'=>'Samsung','6C2F2C'=>'Samsung','6C8336'=>'Samsung','6CB7F4'=>'Samsung','6CF373'=>'Samsung',
        '70F927'=>'Samsung','74458A'=>'Samsung','745F00'=>'Samsung','781FDB'=>'Samsung','7825AD'=>'Samsung',
        '7840E4'=>'Samsung','78471D'=>'Samsung','78521A'=>'Samsung','78595E'=>'Samsung','789ED0'=>'Samsung',
        '78ABBB'=>'Samsung','78BD0B'=>'Samsung','78D6F0'=>'Samsung','78F7BE'=>'Samsung','7C0BC6'=>'Samsung',
        '7C1C68'=>'Samsung','7C6166'=>'Samsung','8018A7'=>'Samsung','805719'=>'Samsung','84119E'=>'Samsung',
        '8425DB'=>'Samsung','843838'=>'Samsung','845181'=>'Samsung','8455A5'=>'Samsung','84A466'=>'Samsung',
        '88329B'=>'Samsung','888322'=>'Samsung','889B39'=>'Samsung','8C71F8'=>'Samsung','8C7712'=>'Samsung',
        '8CBFC0'=>'Samsung','8CC8CD'=>'Samsung','9000DB'=>'Samsung','90187C'=>'Samsung','90F1AA'=>'Samsung',
        '9401C2'=>'Samsung','94350A'=>'Samsung','945103'=>'Samsung','9463D1'=>'Samsung','94B10A'=>'Samsung',
        '94D029'=>'Samsung','980C82'=>'Samsung','98398E'=>'Samsung','9852B1'=>'Samsung','988389'=>'Samsung',
        '9C0298'=>'Samsung','9C3AAF'=>'Samsung','9C65B0'=>'Samsung','9CE6E7'=>'Samsung','A00798'=>'Samsung',
        'A02195'=>'Samsung','A07591'=>'Samsung','A0821F'=>'Samsung','A0B4A5'=>'Samsung','A0CBFD'=>'Samsung',
        'A407B6'=>'Samsung','A48431'=>'Samsung','A49A58'=>'Samsung','A4EBD3'=>'Samsung','A80600'=>'Samsung',
        'A87C01'=>'Samsung','A88195'=>'Samsung','A8F274'=>'Samsung','AC3613'=>'Samsung','AC5A14'=>'Samsung',
        'AC5F3E'=>'Samsung','B047BF'=>'Samsung','B072BF'=>'Samsung','B0C559'=>'Samsung','B0DF3A'=>'Samsung',
        'B0EC71'=>'Samsung','B407F9'=>'Samsung','B43A28'=>'Samsung','B46293'=>'Samsung','B479A7'=>'Samsung',
        'B4EF39'=>'Samsung','B85A73'=>'Samsung','B85E7B'=>'Samsung','B8BB23'=>'Samsung','B8D9CE'=>'Samsung',
        'BC1485'=>'Samsung','BC20A4'=>'Samsung','BC4486'=>'Samsung','BC72B1'=>'Samsung','BC79AD'=>'Samsung',
        'BC851F'=>'Samsung','BC8CCD'=>'Samsung','C06599'=>'Samsung','C09727'=>'Samsung','C0BDD1'=>'Samsung',
        'C44202'=>'Samsung','C45006'=>'Samsung','C4576E'=>'Samsung','C4731E'=>'Samsung','C488E5'=>'Samsung',
        'C81479'=>'Samsung','C819F7'=>'Samsung','C83870'=>'Samsung','C8BA94'=>'Samsung','CC07AB'=>'Samsung',
        'CC3A61'=>'Samsung','CCF9E8'=>'Samsung','D0176A'=>'Samsung','D022BE'=>'Samsung','D059E4'=>'Samsung',
        'D0667B'=>'Samsung','D087E2'=>'Samsung','D0C1B1'=>'Samsung','D0DFC7'=>'Samsung','D487D8'=>'Samsung',
        'D48890'=>'Samsung','D4E8B2'=>'Samsung','D831CF'=>'Samsung','D857EF'=>'Samsung','D890E8'=>'Samsung',
        'DC6672'=>'Samsung','DC7144'=>'Samsung','E09971'=>'Samsung','E0CBEE'=>'Samsung','E4121D'=>'Samsung',
        'E432CB'=>'Samsung','E440E2'=>'Samsung','E458E7'=>'Samsung','E47CF9'=>'Samsung','E492FB'=>'Samsung',
        'E4B021'=>'Samsung','E4E0C5'=>'Samsung','E8039A'=>'Samsung','E81132'=>'Samsung','E83A12'=>'Samsung',
        'E84E84'=>'Samsung','E8508B'=>'Samsung','E8E5D6'=>'Samsung','EC1F72'=>'Samsung','EC9BF3'=>'Samsung',
        'ECE09B'=>'Samsung','F025B7'=>'Samsung','F05A09'=>'Samsung','F06BCA'=>'Samsung','F0E77E'=>'Samsung',
        'F409D8'=>'Samsung','F4428F'=>'Samsung','F47B5E'=>'Samsung','F49F54'=>'Samsung','F8042E'=>'Samsung',
        'F884F2'=>'Samsung','F8D0BD'=>'Samsung','FC0012'=>'Samsung','FC1910'=>'Samsung','FC8F90'=>'Samsung',
        'FCA13E'=>'Samsung','FCF136'=>'Samsung',

        // Xiaomi / Redmi / POCO
        '009EC8'=>'Xiaomi','04CF8C'=>'Xiaomi','0C1DAF'=>'Xiaomi','102AB3'=>'Xiaomi','14F65A'=>'Xiaomi',
        '185936'=>'Xiaomi','2034FB'=>'Xiaomi','2047DA'=>'Xiaomi','286C07'=>'Xiaomi','28E31F'=>'Xiaomi',
        '3480B3'=>'Xiaomi','34CE00'=>'Xiaomi','38A4ED'=>'Xiaomi','4C49E3'=>'Xiaomi','50642B'=>'Xiaomi',
        '508F4C'=>'Xiaomi','584498'=>'Xiaomi','640980'=>'Xiaomi','64B473'=>'Xiaomi','64CC2E'=>'Xiaomi',
        '68DFDD'=>'Xiaomi','742344'=>'Xiaomi','7451BA'=>'Xiaomi','7802F8'=>'Xiaomi','7811DC'=>'Xiaomi',
        '7C1DD9'=>'Xiaomi','8035C1'=>'Xiaomi','88C397'=>'Xiaomi','8CBEBE'=>'Xiaomi','9487E0'=>'Xiaomi',
        '98FAE3'=>'Xiaomi','9C99A0'=>'Xiaomi','AC233F'=>'Xiaomi','ACF7F3'=>'Xiaomi','B0E235'=>'Xiaomi',
        'C40BCB'=>'Xiaomi','C46AB7'=>'Xiaomi','C82832'=>'Xiaomi','D4970B'=>'Xiaomi','D86375'=>'Xiaomi',
        'EC3DFD'=>'Xiaomi','F0B429'=>'Xiaomi','F48B32'=>'Xiaomi','F8A45F'=>'Xiaomi','FC64BA'=>'Xiaomi',

        // OPPO / Realme / OnePlus
        '1C77F6'=>'OPPO','2C5BB8'=>'OPPO','344D2A'=>'OPPO','38295A'=>'OPPO','489D24'=>'OPPO',
        '5433CB'=>'OPPO','583F54'=>'OPPO','6C5C14'=>'OPPO','7C1C4E'=>'OPPO','8C0EE3'=>'OPPO',
        '90E7C4'=>'OPPO','A43BFA'=>'OPPO','B83765'=>'OPPO','BC307D'=>'OPPO','DC4427'=>'OPPO',
        'E89F80'=>'OPPO','947B09'=>'Realme','A8934A'=>'Realme','A03299'=>'OnePlus','C0EEF8'=>'OnePlus',
        '94652D'=>'OnePlus','64A2F9'=>'OnePlus',

        // Vivo
        '0C9D92'=>'Vivo','1801F1'=>'Vivo','1C6758'=>'Vivo','2C5491'=>'Vivo','3C86D1'=>'Vivo',
        '4468AB'=>'Vivo','482CA0'=>'Vivo','50A72B'=>'Vivo','586356'=>'Vivo','68A0F6'=>'Vivo',
        '74A78E'=>'Vivo','849FB5'=>'Vivo','983B8F'=>'Vivo','A82BD8'=>'Vivo','C0F8DA'=>'Vivo',
        'D07E35'=>'Vivo','E02202'=>'Vivo','F83880'=>'Vivo',

        // Huawei / Honor
        '001882'=>'Huawei','001E10'=>'Huawei','002568'=>'Huawei','00259E'=>'Huawei','0034FE'=>'Huawei',
        '00464B'=>'Huawei','00664B'=>'Huawei','009ACD'=>'Huawei','00E0FC'=>'Huawei','04021F'=>'Huawei',
        '0425C5'=>'Huawei','04B0E7'=>'Huawei','04C06F'=>'Huawei','04F938'=>'Huawei','0819A6'=>'Huawei',
        '086361'=>'Huawei','087A4C'=>'Huawei','08E84F'=>'Huawei','0C37DC'=>'Huawei','0C45BA'=>'Huawei',
        '0C96BF'=>'Huawei','101B54'=>'Huawei','104780'=>'Huawei','10C61F'=>'Huawei','14B968'=>'Huawei',
        '18C58A'=>'Huawei','1C1D67'=>'Huawei','1C8E5C'=>'Huawei','2008ED'=>'Huawei','200BC7'=>'Huawei',
        '202BC1'=>'Huawei','20A680'=>'Huawei','20F3A3'=>'Huawei','241FA0'=>'Huawei','2469A5'=>'Huawei',
        '247F3C'=>'Huawei','24DBAC'=>'Huawei','283152'=>'Huawei','285FDB'=>'Huawei','286ED4'=>'Huawei',
        '2C55D3'=>'Huawei','308730'=>'Huawei','30D17E'=>'Huawei','30F335'=>'Huawei','3400A3'=>'Huawei',
        '346AC2'=>'Huawei','34B354'=>'Huawei','34CD6D'=>'Huawei','38BC01'=>'Huawei','3CF808'=>'Huawei',
        '404D8E'=>'Huawei','4846FB'=>'Huawei','486276'=>'Huawei','48DB50'=>'Huawei','4C1FCC'=>'Huawei',
        '4C5499'=>'Huawei','4C8BEF'=>'Huawei','50016B'=>'Huawei','5439DF'=>'Huawei','548998'=>'Huawei',
        '54A51B'=>'Huawei','582AF7'=>'Huawei','5C4CA9'=>'Huawei','5C7D5E'=>'Huawei','5CB395'=>'Huawei',
        '60DE44'=>'Huawei','643E8C'=>'Huawei','64A651'=>'Huawei','7054F5'=>'Huawei','70723C'=>'Huawei',
        '707BE8'=>'Huawei','74A528'=>'Huawei','781DBA'=>'Huawei','78D752'=>'Huawei','7C6097'=>'Huawei',
        '8038BC'=>'Huawei','80717A'=>'Huawei','80B686'=>'Huawei','80FB06'=>'Huawei','84A8E4'=>'Huawei',
        '84DBAC'=>'Huawei','8828B3'=>'Huawei','8853D4'=>'Huawei','8866A5'=>'Huawei','88CEFA'=>'Huawei',
        '88E3AB'=>'Huawei','8C34FD'=>'Huawei','9017AC'=>'Huawei','904E2B'=>'Huawei','90671C'=>'Huawei',
        '94049C'=>'Huawei','94772B'=>'Huawei','94DBDA'=>'Huawei','9C28EF'=>'Huawei','9C37F4'=>'Huawei',
        '9C52F8'=>'Huawei','9CC172'=>'Huawei','A49947'=>'Huawei','AC4E91'=>'Huawei','AC853D'=>'Huawei',
        'ACE215'=>'Huawei','B05B67'=>'Huawei','B41513'=>'Huawei','B43052'=>'Huawei','B8BC1B'=>'Huawei',
        'BC25E0'=>'Huawei','BC7670'=>'Huawei','C4072F'=>'Huawei','C4FF1F'=>'Huawei','C85195'=>'Huawei',
        'C8D15E'=>'Huawei','CC96A0'=>'Huawei','CCA223'=>'Huawei','D02DB3'=>'Huawei','D03E5C'=>'Huawei',
        'D07AB5'=>'Huawei','D440F0'=>'Huawei','D46AA8'=>'Huawei','D46E5C'=>'Huawei','D494E8'=>'Huawei',
        'D4B110'=>'Huawei','D8490B'=>'Huawei','E0191D'=>'Huawei','E0247F'=>'Huawei','E09796'=>'Huawei',
        'E468A3'=>'Huawei','E4C2D1'=>'Huawei','E8CD2D'=>'Huawei','EC233D'=>'Huawei','EC388F'=>'Huawei',
        'ECCB30'=>'Huawei','F4559C'=>'Huawei','F48E92'=>'Huawei','F4C714'=>'Huawei','F4E3FB'=>'Huawei',
        'F80113'=>'Huawei','F83DFF'=>'Huawei','F84A73'=>'Huawei','F898B9'=>'Huawei','F8E811'=>'Huawei',
        'FC48EF'=>'Huawei','FCE33C'=>'Huawei',

        // Google Pixel / Nest
        '3C5AB4'=>'Google','546009'=>'Google','6C4B90'=>'Google','94EB2C'=>'Google','A47733'=>'Google','F4F5D8'=>'Google',

        // Routers / APs (MikroTik, TP-Link, Tenda, D-Link, Ubiquiti)
        '000C42'=>'MikroTik','18FD74'=>'MikroTik','2CC81B'=>'MikroTik','488F5A'=>'MikroTik','4C5E0C'=>'MikroTik',
        '64D154'=>'MikroTik','6C3B6B'=>'MikroTik','744D28'=>'MikroTik','B869F4'=>'MikroTik','C4AD34'=>'MikroTik',
        'CC2DE0'=>'MikroTik','D401C3'=>'MikroTik','D4CA6D'=>'MikroTik','DC2C6E'=>'MikroTik','E48D8C'=>'MikroTik',

        '001D0F'=>'TP-Link','10FEED'=>'TP-Link','14CC20'=>'TP-Link','14CF92'=>'TP-Link','18A6F7'=>'TP-Link',
        '1C3BF3'=>'TP-Link','20DCE6'=>'TP-Link','30B5C2'=>'TP-Link','34E894'=>'TP-Link','50C7BF'=>'TP-Link',
        '54E6FC'=>'TP-Link','60E327'=>'TP-Link','6466B3'=>'TP-Link','647002'=>'TP-Link','6C5AB0'=>'TP-Link',
        '704F57'=>'TP-Link','74EA3A'=>'TP-Link','8416F9'=>'TP-Link','90F652'=>'TP-Link','98DED0'=>'TP-Link',
        'A0F3C1'=>'TP-Link','B04E26'=>'TP-Link','B09575'=>'TP-Link','C025E9'=>'TP-Link','C04A00'=>'TP-Link',
        'C46E1F'=>'TP-Link','D46E0E'=>'TP-Link','D807B6'=>'TP-Link','E894F6'=>'TP-Link','EC086B'=>'TP-Link',
        'F4EC38'=>'TP-Link','F81A67'=>'TP-Link',

        '0495E6'=>'Tenda','500F80'=>'Tenda','B0DFC1'=>'Tenda','C83A35'=>'Tenda','CC2D21'=>'Tenda','D83214'=>'Tenda',

        // Laptops / Wi-Fi Chipsets (Intel, Realtek, Dell, HP, Lenovo)
        '001500'=>'Intel','001B77'=>'Intel','001E64'=>'Intel','00216A'=>'Intel','0024D6'=>'Intel',
        '0026C6'=>'Intel','08D40C'=>'Intel','183DEA'=>'Intel','28B2BD'=>'Intel','3413E8'=>'Intel',
        '3C970E'=>'Intel','4851B7'=>'Intel','5C514F'=>'Intel','6805CA'=>'Intel','7C7A91'=>'Intel',
        '8086F2'=>'Intel','843A4B'=>'Intel','8C8D28'=>'Intel','94659C'=>'Intel','A44CC8'=>'Intel',
        'B4B676'=>'Intel','DC5360'=>'Intel','E4A7A0'=>'Intel','F48C50'=>'Intel',
        '00E04C'=>'Realtek','0014D1'=>'TRENDnet','001346'=>'D-Link','00179A'=>'D-Link','00195B'=>'D-Link',
        '001B11'=>'D-Link','001CF0'=>'D-Link','001E58'=>'D-Link','002191'=>'D-Link','0022B0'=>'D-Link',
        '002401'=>'D-Link','00265A'=>'D-Link',
    );

    return isset($ouiMap[$oui]) ? $ouiMap[$oui] : '';
}

/**
 * Lookup public (non-randomized) OUI via local JSON cache + optional fast maclookup.app query.
 */
function ngwLookupCachedRemoteVendor($mac) {
    $norm = ngwNormalizeMac($mac);
    if ($norm === '' || $norm === '00:00:00:00:00:00') {
        return '';
    }
    if (ngwIsRandomizedMac($norm)) {
        return '';
    }

    $oui = strtoupper(str_replace(':', '', substr($norm, 0, 8)));
    $rootDir = realpath(__DIR__ . '/..');
    if ($rootDir === false) {
        $rootDir = dirname(__DIR__);
    }
    $dataDir = $rootDir . '/data';
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0750, true);
    }
    $cacheFile = $dataDir . '/mac_vendor_cache.json';
    $cache = array();
    if (is_file($cacheFile)) {
        $dec = @json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($dec)) {
            $cache = $dec;
        }
    }

    if (array_key_exists($oui, $cache)) {
        return (string)$cache[$oui];
    }

    $vendor = '';
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(array(
            'http' => array(
                'method'  => 'GET',
                'timeout' => 1.2,
                'header'  => "User-Agent: NextGanWiFi/1.0\r\n",
            ),
            'ssl' => array(
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ),
        ));
        $resp = @file_get_contents('https://api.maclookup.app/v2/macs/' . rawurlencode($oui), false, $ctx);
        if (is_string($resp) && $resp !== '') {
            $json = @json_decode($resp, true);
            if (is_array($json) && !empty($json['found']) && !empty($json['company'])) {
                $vendor = trim((string)$json['company']);
            }
        }
    }

    // Cache non-empty result (and avoid caching failed offline checks so future online checks can resolve it)
    if ($vendor !== '') {
        $cache[$oui] = $vendor;
        @file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    return $vendor;
}

/**
 * Clean a raw DHCP hostname into a readable device model name.
 */
function ngwFormatModelFromHostname($hostname) {
    $clean = trim((string)$hostname);
    $clean = trim(str_replace(array('_', '+'), ' ', $clean));
    $clean = preg_replace('/\s+/', ' ', $clean);
    if ($clean === '' || strtolower($clean) === 'localhost' || strtolower($clean) === 'unknown') {
        return '';
    }
    // Generic random android DHCP hostname e.g. android-a1b2c3d4e5f6
    if (preg_match('/^android-[0-9a-f]{8,}$/i', $clean)) {
        return '';
    }
    // Replace hyphens cleanly for readable phone model names
    if (preg_match('/^(?:Redmi|POCO|Xiaomi|Mi|Galaxy|SM-|OPPO|CPH|vivo|V\d{4}|realme|RMX|TECNO|Infinix|itel|OnePlus|Pixel|HUAWEI|HONOR|Nokia|moto|iPhone|iPad|Symphony|Walton|MacBook)/i', $clean)) {
        $clean = str_replace('-', ' ', $clean);
        $clean = preg_replace('/\s+/', ' ', $clean);
    }
    return trim($clean);
}

/**
 * Classify Vendor, Model & Device Type using DHCP Hostname, Vendor-Class-ID, and MAC OUI.
 */
function ngwClassifyDeviceFromMetadata($mac = '', $hostname = '', $classId = '') {
    $normMac = ngwNormalizeMac($mac);
    $isRandomMac = ($normMac !== '' && ngwIsRandomizedMac($normMac));
    $host = trim((string)$hostname);
    $cls = strtolower(trim((string)$classId));

    $vendor = '';
    $model = ngwFormatModelFromHostname($host);
    $deviceType = '';
    $source = '';

    $hostLower = strtolower($host);

    // 1. Inspect DHCP Hostname patterns (works even when phone uses Randomized MAC!)
    if ($host !== '' && strtolower($host) !== 'localhost') {
        if (preg_match('/\b(iphone)\b/i', $hostLower) || stripos($hostLower, 'iphone') !== false) {
            $vendor = 'Apple';
            $deviceType = 'iPhone (iOS)';
            if ($model === '') $model = 'iPhone';
        } elseif (stripos($hostLower, 'ipad') !== false) {
            $vendor = 'Apple';
            $deviceType = 'iPad / Tablet (iPadOS)';
            if ($model === '') $model = 'iPad';
        } elseif (preg_match('/macbook|imac|mac-mini|macstudio/i', $hostLower)) {
            $vendor = 'Apple';
            $deviceType = 'Mac / Laptop (macOS)';
        } elseif (preg_match('/galaxy|sm-[a-z0-9]+|samsung/i', $hostLower)) {
            $vendor = 'Samsung';
            if (preg_match('/tab|sm-[tx]\d+/i', $hostLower)) {
                $deviceType = 'Tablet (Android)';
            } else {
                $deviceType = 'Smartphone (Android)';
            }
        } elseif (preg_match('/redmi|poco|xiaomi|mi[-_\s]?note|mi[-_\s]?\d|m2[01]\d{2}/i', $hostLower)) {
            $vendor = 'Xiaomi';
            $deviceType = 'Smartphone (Android)';
        } elseif (preg_match('/realme|rmx\d+/i', $hostLower)) {
            $vendor = 'Realme';
            $deviceType = 'Smartphone (Android)';
        } elseif (preg_match('/oneplus|nord|ne\d{4}|le\d{4}|cph24/i', $hostLower)) {
            $vendor = 'OnePlus';
            $deviceType = 'Smartphone (Android)';
        } elseif (preg_match('/oppo|cph\d+/i', $hostLower)) {
            $vendor = 'OPPO';
            $deviceType = 'Smartphone (Android)';
        } elseif (preg_match('/vivo|iqoo|v2\d{3}/i', $hostLower)) {
            $vendor = 'Vivo';
            $deviceType = 'Smartphone (Android)';
        } elseif (stripos($hostLower, 'tecno') !== false) {
            $vendor = 'Tecno (Transsion)';
            $deviceType = 'Smartphone (Android)';
        } elseif (stripos($hostLower, 'infinix') !== false) {
            $vendor = 'Infinix (Transsion)';
            $deviceType = 'Smartphone (Android)';
        } elseif (preg_match('/\bitel\b|itel-/i', $hostLower)) {
            $vendor = 'Itel (Transsion)';
            $deviceType = 'Smartphone (Android)';
        } elseif (preg_match('/pixel/i', $hostLower)) {
            $vendor = 'Google Pixel';
            $deviceType = 'Smartphone (Android)';
        } elseif (preg_match('/huawei/i', $hostLower)) {
            $vendor = 'Huawei';
            $deviceType = 'Smartphone (Android / HarmonyOS)';
        } elseif (preg_match('/honor/i', $hostLower)) {
            $vendor = 'Honor';
            $deviceType = 'Smartphone (Android)';
        } elseif (preg_match('/nokia/i', $hostLower)) {
            $vendor = 'Nokia (HMD)';
            $deviceType = 'Smartphone (Android)';
        } elseif (preg_match('/moto|motorola/i', $hostLower)) {
            $vendor = 'Motorola';
            $deviceType = 'Smartphone (Android)';
        } elseif (preg_match('/symphony/i', $hostLower)) {
            $vendor = 'Symphony';
            $deviceType = 'Smartphone (Android)';
        } elseif (preg_match('/walton|primo/i', $hostLower)) {
            $vendor = 'Walton';
            $deviceType = 'Smartphone (Android)';
        } elseif (preg_match('/^(desktop|laptop|win)-/i', $hostLower)) {
            $deviceType = 'Laptop / PC (Windows)';
        } elseif (preg_match('/(bravia|smart[-_\s]?tv|android[-_\s]?tv|mitv|firetv|chromecast)/i', $hostLower)) {
            $deviceType = 'Smart TV / Media Box';
        } elseif (preg_match('/^(tp-link|archer|tenda|mikrotik|totolink|netis|mercusys|d-link)/i', $hostLower)) {
            $deviceType = 'WiFi Router / Access Point';
        } elseif (preg_match('/^android-/i', $hostLower)) {
            $deviceType = 'Smartphone / Tablet (Android)';
        }

        if ($vendor !== '' || $model !== '') {
            $source = 'dhcp_hostname';
        }
    }

    // 2. Inspect DHCP Vendor Class ID if type still undecided
    if ($cls !== '') {
        if ($deviceType === '' && strpos($cls, 'android-dhcp') !== false) {
            $deviceType = 'Smartphone / Tablet (Android)';
            if ($source === '') $source = 'dhcp_class_id';
        } elseif ($deviceType === '' && strpos($cls, 'msft') !== false) {
            $deviceType = 'Laptop / PC (Windows)';
            if ($source === '') $source = 'dhcp_class_id';
        }
    }

    // 3. MAC OUI lookup for non-randomized hardware MACs
    $macVendor = '';
    if ($normMac !== '' && $normMac !== '00:00:00:00:00:00' && !$isRandomMac) {
        $macVendor = ngwLookupOfflineMacVendor($normMac);
        if ($macVendor === '') {
            $macVendor = ngwLookupCachedRemoteVendor($normMac);
        }
        if ($macVendor !== '') {
            if ($vendor === '') {
                $vendor = $macVendor;
                $source = ($source !== '') ? $source : 'mac_oui';
            }
            if ($deviceType === '') {
                if ($macVendor === 'Apple') {
                    $deviceType = 'iPhone / iPad / Mac';
                } elseif (in_array($macVendor, array('Samsung', 'Xiaomi', 'OPPO', 'Realme', 'OnePlus', 'Vivo', 'Huawei', 'Google'), true)) {
                    $deviceType = 'Smartphone / Mobile Device';
                } elseif (in_array($macVendor, array('MikroTik', 'TP-Link', 'Tenda', 'D-Link', 'TRENDnet'), true)) {
                    $deviceType = 'WiFi Router / Access Point';
                } elseif (in_array($macVendor, array('Intel', 'Realtek'), true)) {
                    $deviceType = 'Laptop / PC / Network Adapter';
                } else {
                    $deviceType = 'WiFi Client Device';
                }
            }
        }
    }

    if ($deviceType === '' && $model !== '') {
        $deviceType = 'Mobile / WiFi Client';
    }

    return array(
        'vendor'         => $vendor,
        'model'          => $model,
        'device_type'    => $deviceType,
        'hostname'       => $host,
        'class_id'       => trim((string)$classId),
        'is_random_mac'  => $isRandomMac,
        'source'         => $source,
    );
}

/**
 * Resolve device vendor/model/type for a single Hotspot voucher using RouterOS DHCP Leases,
 * Hotspot Hosts, MAC OUI, and 30-day saved backend history fallback.
 */
function ngwResolveVoucherDeviceInfo($API, $session, $username, $mac = '', $ip = '', $historyRec = null, $prefetchedLeases = null) {
    $normMac = ngwNormalizeMac($mac);
    $cleanIp = trim((string)$ip);
    // Strip display tags like "10.5.50.10 (Active)"
    if (strpos($cleanIp, ' ') !== false) {
        $cleanIp = trim(explode(' ', $cleanIp)[0]);
    }

    $hostname = '';
    $classId = '';

    // 1. Check live MikroTik DHCP Leases (by MAC or IP)
    if ($prefetchedLeases !== null && is_array($prefetchedLeases)) {
        foreach ($prefetchedLeases as $lease) {
            if (!is_array($lease)) continue;
            $lMac = isset($lease['mac-address']) ? ngwNormalizeMac($lease['mac-address']) : '';
            $lAMac = isset($lease['active-mac-address']) ? ngwNormalizeMac($lease['active-mac-address']) : '';
            $lIp = isset($lease['address']) ? trim((string)$lease['address']) : '';
            $lAIp = isset($lease['active-address']) ? trim((string)$lease['active-address']) : '';
            if (($normMac !== '' && ($lMac === $normMac || $lAMac === $normMac))
                || ($cleanIp !== '' && ($lIp === $cleanIp || $lAIp === $cleanIp))) {
                $hostname = isset($lease['host-name']) ? trim((string)$lease['host-name']) : '';
                $classId = isset($lease['class-id']) ? trim((string)$lease['class-id']) : (isset($lease['vendor-class-id']) ? trim((string)$lease['vendor-class-id']) : '');
                if ($normMac === '' && $lMac !== '') $normMac = $lMac;
                break;
            }
        }
    } elseif ($API && !empty($API->connected)) {
        $leaseHit = array();
        if ($normMac !== '' && $normMac !== '00:00:00:00:00:00') {
            $leaseHit = $API->comm('/ip/dhcp-server/lease/print', array('?mac-address' => $normMac));
            if (!is_array($leaseHit) || empty($leaseHit[0]['.id'])) {
                $leaseHit = $API->comm('/ip/dhcp-server/lease/print', array('?active-mac-address' => $normMac));
            }
        }
        if ((!is_array($leaseHit) || empty($leaseHit[0]['.id'])) && $cleanIp !== '' && $cleanIp !== '-') {
            $leaseHit = $API->comm('/ip/dhcp-server/lease/print', array('?address' => $cleanIp));
        }
        if (is_array($leaseHit) && !empty($leaseHit[0]) && is_array($leaseHit[0])) {
            $hostname = isset($leaseHit[0]['host-name']) ? trim((string)$leaseHit[0]['host-name']) : '';
            $classId = isset($leaseHit[0]['class-id']) ? trim((string)$leaseHit[0]['class-id']) : (isset($leaseHit[0]['vendor-class-id']) ? trim((string)$leaseHit[0]['vendor-class-id']) : '');
            if ($normMac === '' && !empty($leaseHit[0]['mac-address'])) {
                $normMac = ngwNormalizeMac($leaseHit[0]['mac-address']);
            }
        }
    }

    // Fallback to previously captured history hostname/class_id if DHCP lease expired
    if ($hostname === '' && is_array($historyRec) && !empty($historyRec['device_hostname'])) {
        $hostname = (string)$historyRec['device_hostname'];
    }
    if ($classId === '' && is_array($historyRec) && !empty($historyRec['device_class_id'])) {
        $classId = (string)$historyRec['device_class_id'];
    }

    $classified = ngwClassifyDeviceFromMetadata($normMac, $hostname, $classId);

    // If still empty, pull previously detected vendor/model/type from backend 30-day history
    if ($classified['vendor'] === '' && is_array($historyRec) && !empty($historyRec['device_vendor'])) {
        $classified['vendor'] = (string)$historyRec['device_vendor'];
    }
    if ($classified['model'] === '' && is_array($historyRec) && !empty($historyRec['device_model'])) {
        $classified['model'] = (string)$historyRec['device_model'];
    }
    if ($classified['device_type'] === '' && is_array($historyRec) && !empty($historyRec['device_type'])) {
        $classified['device_type'] = (string)$historyRec['device_type'];
    }

    // Persist newly discovered device info back into 30-day history
    if ((string)$session !== '' && trim((string)$username) !== ''
        && ($classified['vendor'] !== '' || $classified['model'] !== '' || $classified['device_type'] !== '' || $classified['hostname'] !== '')
        && function_exists('ngwSaveVoucherDeviceInfo')) {
        ngwSaveVoucherDeviceInfo($session, $username, $classified);
    }

    return $classified;
}

/**
 * Render clean HTML displaying Vendor / Brand, Phone Model, and Device Type for Reset Voucher & User Details.
 */
function ngwRenderDeviceVendorHtml($deviceInfo, $langid = 'en', $hasAnyConnection = false) {
    $isBn = ($langid === 'bn');
    $vendor = isset($deviceInfo['vendor']) ? trim((string)$deviceInfo['vendor']) : '';
    $model = isset($deviceInfo['model']) ? trim((string)$deviceInfo['model']) : '';
    $type = isset($deviceInfo['device_type']) ? trim((string)$deviceInfo['device_type']) : '';
    $hostname = isset($deviceInfo['hostname']) ? trim((string)$deviceInfo['hostname']) : '';
    $isRandom = !empty($deviceInfo['is_random_mac']);

    if ($vendor === '' && $model === '' && $type === '') {
        if ($isRandom) {
            $msg = $isBn
                ? 'প্রাইভেট / র‍্যান্ডমাইজড MAC (ডিভাইস হোস্টনেম লুকানো রয়েছে)'
                : 'Private / Randomized MAC (device hostname hidden by phone)';
            return "<span class='text-warning'><i class='fa fa-mobile'></i> " . htmlspecialchars($msg) . "</span>";
        }
        $msg = !$hasAnyConnection
            ? ($isBn ? 'এখনও কোনো ডিভাইসে ব্যবহৃত হয়নি' : 'Not used on any device yet')
            : ($isBn ? 'ডিভাইসের নাম/ভ্যান্ডোর পাওয়া যায়নি' : 'Unknown device / vendor');
        return "<span style='opacity:0.8;'>" . htmlspecialchars($msg) . "</span>";
    }

    // Pick icon matching device type
    $icon = 'fa-microchip';
    $typeLow = strtolower($type);
    if (strpos($typeLow, 'iphone') !== false || strpos($typeLow, 'smartphone') !== false || strpos($typeLow, 'mobile') !== false) {
        $icon = 'fa-mobile';
    } elseif (strpos($typeLow, 'ipad') !== false || strpos($typeLow, 'tablet') !== false) {
        $icon = 'fa-tablet';
    } elseif (strpos($typeLow, 'laptop') !== false || strpos($typeLow, 'mac') !== false || strpos($typeLow, 'pc') !== false) {
        $icon = 'fa-laptop';
    } elseif (strpos($typeLow, 'router') !== false) {
        $icon = 'fa-wifi';
    } elseif (strpos($typeLow, 'tv') !== false) {
        $icon = 'fa-television';
    }

    $headlineParts = array();
    if ($vendor !== '') {
        $headlineParts[] = "<b>" . htmlspecialchars($vendor) . "</b>";
    }
    if ($model !== '' && strcasecmp($model, $vendor) !== 0) {
        $headlineParts[] = "<code>" . htmlspecialchars($model) . "</code>";
    }
    $headline = !empty($headlineParts) ? implode(' — ', $headlineParts) : htmlspecialchars($type);

    $metaParts = array();
    if ($type !== '') {
        $metaParts[] = ($isBn ? 'ধরন: ' : 'Type: ') . htmlspecialchars($type);
    }
    if ($hostname !== '' && strcasecmp($hostname, $model) !== 0) {
        $metaParts[] = ($isBn ? 'হোস্টনেম: ' : 'Hostname: ') . htmlspecialchars($hostname);
    }

    $html = "<i class='fa " . $icon . " text-info' style='font-size:15px;'></i> " . $headline;
    if (!empty($metaParts)) {
        $html .= "<div style='margin-top:4px; font-size:12px; opacity:0.88;'>" . implode(' &nbsp;|&nbsp; ', $metaParts) . "</div>";
    }
    return $html;
}
?>
