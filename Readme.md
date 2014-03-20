# What is George?

George is a bridge between Gerrit - GitHub - Travis with a pinch of php_codesniffer

## Download to local directory
```
curl -sL https://github.com/mneuhaus/George/releases/download/0.0.3/george-0.0.3.phar > george.phar
chmod +x george.phar
```

## Move to global location

### OSX
```
mv george.phar /usr/local/bin/george
```

## Commands

### Sync

This file is needed by george to have all the proper information to pull + push

**.george.yaml**
```
gerrit:
  project: Packages/TYPO3.Neos
  username: username
  password: password

github:
  username: username
  password: password
  repository: mneuhaus/TYPO3.Neos

branches:
  - 'master'
```