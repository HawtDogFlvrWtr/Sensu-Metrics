<?php
ini_set('memory_limit', '-1');
include 'vmwarephp/library/Vmwarephp/Service.php';
include 'vmwarephp/library/Vmwarephp/Vhost.php';
include 'vmwarephp/library/Vmwarephp/WsdlClassMapper.php';
include 'vmwarephp/library/Vmwarephp/SoapClient.php';
include 'vmwarephp/library/Vmwarephp/ManagedObject.php';
include 'vmwarephp/library/Vmwarephp/TypeConverter.php';
include 'vmwarephp/library/Vmwarephp/Factory/ManagedObject.php';
include 'vmwarephp/library/Vmwarephp/Factory/PropertyFilterSpec.php';
include 'vmwarephp/library/Vmwarephp/Factory/Service.php';
include 'vmwarephp/library/Vmwarephp/Factory/SoapClient.php';
include 'vmwarephp/library/Vmwarephp/Factory/SoapMessage.php';
include 'vmwarephp/library/Vmwarephp/Exception/CannotCreateSoapClient.php';
include 'vmwarephp/library/Vmwarephp/Exception/InvalidTraversalPropertyFormat.php';
include 'vmwarephp/library/Vmwarephp/Exception/InvalidVhost.php';
include 'vmwarephp/library/Vmwarephp/Exception/Soap.php';
include 'vmwarephp/library/Vmwarephp/Extensions/Datastore.php';
include 'vmwarephp/library/Vmwarephp/Extensions/Folder.php';
include 'vmwarephp/library/Vmwarephp/Extensions/PropertyCollector.php';
include 'vmwarephp/library/Vmwarephp/Extensions/SessionManager.php';
include 'vmwarephp/library/Vmwarephp/Extensions/VirtualMachine.php';
include 'vmwarephp/library/Vmwarephp/TypeDefinitions.inc';

$vmHost = 'HOSTNAME';
$vmUsername = 'DOMAIN\USERNAME';
$vmPassword = 'PASSWORD';

function searchForId($system, $array) {
   foreach ($array as $key => $val) {
     foreach ($val as $keyName => $valueName) {
       if ($valueName === $system) {
         return $key;
       }
     }
   }
   return null;
}

function cleanHost($hostname) {
  $ipPattern = '/\d+\.\d+\.\d+\.\d+/';
  if (preg_match($ipPattern, $hostname)>0) {
    $hostName = str_replace(".", "-", $hostname);
  } else {
    $hostName = explode(".", $hostname);
    $hostName = $hostName[0];
  }
  return $hostName;
}


# GET CLUSTERS
$vhost = new \Vmwarephp\Vhost($vmHost, $vmUsername, $vmPassword);
$currentTime = time();
$virtualHosts = $vhost->findAllManagedObjects('ClusterComputeResource', array('name', 'host'));
$vm_list = array();
$clusterMapping = array();
foreach($virtualHosts as $key=>$vm) {
  $vm_info = array();
  # Kill the fqdn and check for IP
  $vm_info['hostname'] = cleanHost($vm->name);
  $vm_info['host'] = $vm->host;

  $vm_list[$vm_info['hostname']] = $vm_info;
  foreach ($vm_list as $key=>$value) {
    foreach ($value['host'] as $keyHost=>$keyValue) {
      $hostClean = cleanHost($keyValue->name);
      $clusterMapping[$key][$keyHost] = $hostClean;
    }
  }
}

# GET PHYSICAL HOSTS
$currentTime = time();
$virtualHosts = $vhost->findAllManagedObjects('HostSystem', array('name', 'summary', 'runtime'));
$vm_list = array();
$hostMapping = array();
foreach($virtualHosts as $key=>$vm) {
  $vm_info = array();
  # Kill the fqdn and check for IP
  $ipPattern = '/\d+\.\d+\.\d+\.\d+/';
  if (preg_match($ipPattern, $vm->name)>0) {
    $hostName = str_replace(".", "-", $vm->name);
  } else {
    $hostName = explode(".", $vm->name);
    $hostName = $hostName[0];
  }
  $vm_info['hostname'] = $hostName;
  $vm_info['summary'] = $vm->summary;
  $vm_info['runtime'] = $vm->runtime;
  foreach ($vm->vm as $key=>$value) {
    $hostMapping[$hostName][$key] = $value->guest->hostName;
  }
  $vm_list[$vm_info['hostname']] = $vm_info;
}
foreach($vm_list as $key=>$value) {
  if ($value['summary']->quickStats->uptime) {
    # Calculate the max actual CPU mhz for the host
    $cpuSpeed = $value['summary']->hardware->cpuMhz;
    $numCores = $value['summary']->hardware->numCpuCores;
    $maxCpuMhz = $cpuSpeed * $numCores;
    if ($value['runtime']->inMaintenanceMode == "") {
      $maintenanceMode = 0;
    } else {
      $maintenanceMode = 1;
    }
    if ($value['runtime']->standbyMode == 'none') {
      $standbyMode = 0;
    } else {
      $standbyMode = 1;
    }
    $topHost = searchForId($key, $clusterMapping);
    if ($topHost) {
      $key = $key.".".$topHost."_VMCATCH_HOST";
    } else {
      $key = $key.".VMCATCH_HOST";
    }
    $convertMemory = $value['summary']->hardware->memorySize / 1024 / 1024;
    $freeMemory = $convertMemory - $value['summary']->quickStats->overallMemoryUsage;
    $freeCpu = $maxCpuMhz - $value['summary']->quickStats->overallCpuUsage;
    echo "$key.uptime ".$value['summary']->quickStats->uptime." ".$currentTime."\r\n";
    echo "$key.maxCpuMhz ".$maxCpuMhz." ".$currentTime."\r\n";
    echo "$key.overallCpuUsage ".$value['summary']->quickStats->overallCpuUsage." ".$currentTime."\r\n";
    echo "$key.freeCpu ".$freeCpu." ".$currentTime."\r\n";
    echo "$key.memorySizeMB ".$convertMemory." ".$currentTime."\r\n";
    echo "$key.freeMemoryMB ".$freeMemory." ".$currentTime."\r\n";
    echo "$key.overallMemoryUsageMB ".$value['summary']->quickStats->overallMemoryUsage." ".$currentTime."\r\n";
    echo "$key.maintenanceMode ".$maintenanceMode." ".$currentTime."\r\n";
    echo "$key.standbyMode ".$standbyMode." ".$currentTime."\r\n";
    foreach ($value['runtime']->healthSystemRuntime->systemHealthInfo->numericSensorInfo as $value) {
      if ($value->baseUnits == "Degrees C" || $value->baseUnits == "RPM") {
        if ($value->baseUnits == "Degrees C"){
          $value->currentReading = ((9/5) * substr($value->currentReading, 0, -2)) + (32).'00';
        }
        $sensorName = explode("---", $value->name);
        echo $key.".".rtrim(str_replace(' ', '_', $sensorName[0]), '_')." ".substr($value->currentReading, 0, -2)." ".$currentTime."\r\n";
      }
    }
  }
}

# GET VIRTUALS
$virtualMachines = $vhost->findAllManagedObjects('VirtualMachine', array('name', 'configStatus', 'summary', 'runtime'));
$vm_list = array();
foreach($virtualMachines as $key=>$vm) {
  $vm_info = array();
  #$vm_info['object'] = $vm;
  $vm_info['summary'] = $vm->summary;
  $vm_info['runtime'] = $vm->runtime;
  //$vm_info['hardware'] = $vm->getHardware();
  $vm_info['guest_info'] = $vm->getGuestInfo();
  $vm_info['hostname'] = $vm_info['guest_info']->hostName;

  $vm_list[$vm_info['hostname']] = $vm_info;
}
foreach($vm_list as $key=>$value) {
  if ($value['guest_info']->disk && $value['guest_info']->guestState == 'running') {
    $topHost = searchForId($key, $hostMapping);
    $cleanHost = explode(".", $key);
    if ($topHost) {
      $key = $cleanHost[0].".".$topHost."_VMCATCH";
    } else {
      $key = $cleanHost[0].".VMCATCH";
    }
    echo "$key.overallCpuUsage ". $value['summary']->quickStats->overallCpuUsage." ".$currentTime."\r\n";
    echo "$key.maxCpuUsage ". $value['runtime']->maxCpuUsage." ".$currentTime."\r\n";
    echo "$key.overallCpuDemand ". $value['summary']->quickStats->overallCpuDemand." ".$currentTime."\r\n";
    echo "$key.memorySizeMB ". $value['summary']->config->memorySizeMB." ".$currentTime."\r\n";
    echo "$key.numCpu ". $value['summary']->config->numCpu." ".$currentTime."\r\n";
    echo "$key.numEthernetCards ". $value['summary']->config->numEthernetCards." ".$currentTime."\r\n";
    echo "$key.guestMemoryUsage ". $value['summary']->quickStats->guestMemoryUsage." ".$currentTime."\r\n";
    echo "$key.hostMemoryUsage ". $value['summary']->quickStats->hostMemoryUsage." ".$currentTime."\r\n";
    echo "$key.maxMemoryUsage ". $value['runtime']->maxMemoryUsage." ".$currentTime."\r\n";
    echo "$key.swappedMemory ". $value['summary']->quickStats->swappedMemory." ".$currentTime."\r\n";
    $status = $value['summary']->overallStatus;
    if ($status == 'gray') {
      $overallStatus = 0;
    } else if ($status == 'green') {
      $overallStatus = 1;
    } else if ($status == 'yellow') {
      $overallStatus = 2;
    } else if ($status == 'red') {
      $overallStatus = 3;
    } else {
      $overallStatus = 0;
    }
    echo "$key.overallStatus ". $overallStatus." ".$currentTime."\r\n";
    echo "$key.uptimeSeconds ". $value['summary']->quickStats->uptimeSeconds." ".$currentTime."\r\n";
    echo "$key.ipAddress ". $value['summary']->guest->ipAddress." ".$currentTime."\r\n";
    foreach ($value['guest_info']->disk as $keyDisk=>$valueDisk) {
    
      $diskPath = preg_replace('/[\\\]/s',"",$valueDisk->diskPath);
      //if ($diskPath == '_') {$diskPath = 'root'; }
      if ($diskPath == '/') {
        $diskPath = '/root';
      }
      echo "$key.capacity.$diskPath $valueDisk->capacity $currentTime\r\n";
      echo "$key.freespace.$diskPath $valueDisk->freeSpace $currentTime\r\n";
    }
  }
}

?>
