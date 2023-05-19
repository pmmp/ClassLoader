<?php

/*
 * PocketMine Standard PHP Library
 * Copyright (C) 2014-2018 PocketMine Team <https://github.com/PocketMine/PocketMine-SPL>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
*/

declare(strict_types=1);

use pmmp\thread\ThreadSafe;
use pmmp\thread\ThreadSafeArray;

class BaseClassLoader extends ThreadSafe implements DynamicClassLoader{

	/**
	 * @var ThreadSafeArray|string[]
	 * @phpstan-var ThreadSafeArray<int, string>
	 */
	private $fallbackLookup;
	/**
	 * @var ThreadSafeArray|string[][]
	 * @phpstan-var ThreadSafeArray<string, ThreadSafeArray<int, string>>
	 */
	private $psr4Lookup;

	public function __construct(){
		$this->fallbackLookup = new ThreadSafeArray();
		$this->psr4Lookup = new ThreadSafeArray();
	}

	protected function normalizePath(string $path) : string{
		$parts = explode("://", $path, 2);
		if(count($parts) === 2){
			return $parts[0] . "://" . str_replace('/', DIRECTORY_SEPARATOR, $parts[1]);
		}
		return str_replace('/', DIRECTORY_SEPARATOR, $parts[0]);
	}

	public function addPath(string $namespacePrefix, string $path, bool $prepend = false) : void{
		$path = $this->normalizePath($path);
		if($namespacePrefix === '' || $namespacePrefix === '\\'){
			$this->fallbackLookup->synchronized(function() use ($path, $prepend) : void{
				$this->appendOrPrependLookupEntry($this->fallbackLookup, $path, $prepend);
			});
		}else{
			$namespacePrefix = trim($namespacePrefix, '\\') . '\\';
			$this->psr4Lookup->synchronized(function() use ($namespacePrefix, $path, $prepend) : void{
				$list = $this->psr4Lookup[$namespacePrefix] ?? null;
				if($list === null){
					$list = $this->psr4Lookup[$namespacePrefix] = new ThreadSafeArray();
				}
				$this->appendOrPrependLookupEntry($list, $path, $prepend);
			});
		}
	}

	/**
	 * @phpstan-param ThreadSafeArray<int, string> $list
	 */
	protected function appendOrPrependLookupEntry(ThreadSafeArray $list, string $entry, bool $prepend) : void{
		if($prepend){
			$entries = $this->getAndRemoveLookupEntries($list);
			$list[] = $entry;
			foreach($entries as $removedEntry){
				$list[] = $removedEntry;
			}
		}else{
			$list[] = $entry;
		}
	}

	/**
	 * @return string[]
	 *
	 * @phpstan-param ThreadSafeArray<int, string> $list
	 * @phpstan-return list<string>
	 */
	protected function getAndRemoveLookupEntries(ThreadSafeArray $list) : array{
		$entries = [];
		while(($entry = $list->shift()) !== null){
			$entries[] = $entry;
		}
		return $entries;
	}

	public function register(bool $prepend = false) : bool{
		return spl_autoload_register(function(string $name) : void{
			$this->loadClass($name);
		}, true, $prepend);
	}

	/**
	 * Called when there is a class to load
	 */
	public function loadClass(string $name) : bool{
		$path = $this->findClass($name);
		if($path !== null){
			include($path);
			if(!class_exists($name, false) and !interface_exists($name, false) and !trait_exists($name, false)){
				return false;
			}

			if(method_exists($name, "onClassLoaded") and (new ReflectionClass($name))->getMethod("onClassLoaded")->isStatic()){
				$name::onClassLoaded();
			}

			return true;
		}

		return false;
	}

	/**
	 * Returns the path for the class, if any
	 */
	public function findClass(string $name) : ?string{
		$baseName = str_replace("\\", DIRECTORY_SEPARATOR, $name);

		foreach($this->fallbackLookup as $path){
			$filename = $path . DIRECTORY_SEPARATOR . $baseName . ".php";
			if(file_exists($filename)){
				return $filename;
			}
		}

		// PSR-4 lookup
		$logicalPathPsr4 = $baseName . ".php";

		return $this->psr4Lookup->synchronized(function() use ($name, $logicalPathPsr4) : ?string{
			$subPath = $name;
			while(false !== $lastPos = strrpos($subPath, '\\')){
				$subPath = substr($subPath, 0, $lastPos);
				$search = $subPath . '\\';
				$lookup = $this->psr4Lookup[$search] ?? null;
				if($lookup !== null){
					$pathEnd = DIRECTORY_SEPARATOR . substr($logicalPathPsr4, $lastPos + 1);
					foreach($lookup as $dir){
						if(file_exists($file = $dir . $pathEnd)){
							return $file;
						}
					}
				}
			}
			return null;
		});
	}
}
