name: Build ChatFixer

on:
  push:
    branches: [ main, master ]
    tags: [ 'v*' ]
  pull_request:
    branches: [ main, master ]
  workflow_dispatch:

jobs:
  build:
    name: Build PHAR
    runs-on: ubuntu-latest

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: mbstring, gd, zip, yaml
          coverage: none

      - name: Download DevTools
        run: |
          curl -L -o /tmp/DevTools.phar https://github.com/pmmp/DevTools/releases/latest/download/DevTools.phar
          ls -la /tmp/DevTools.phar

      - name: Prepare plugin folder
        run: |
          mkdir -p /tmp/build/ChatFixer
          rsync -av \
            --exclude='.git' \
            --exclude='.github' \
            --exclude='build' \
            --exclude='pmmp' \
            --exclude='*.phar' \
            ./ /tmp/build/ChatFixer/

      - name: List files to build
        run: |
          echo "=== Files in build folder ==="
          ls -la /tmp/build/ChatFixer/
          echo "=== plugin.yml check ==="
          cat /tmp/build/ChatFixer/plugin.yml

      - name: Build PHAR with DevTools
        run: |
          cd /tmp/build
          php -dphar.readonly=0 /tmp/DevTools.phar --make ChatFixer --out ChatFixer.phar
          echo "=== Build result ==="
          ls -la /tmp/build/

      - name: Upload PHAR artifact
        uses: actions/upload-artifact@v4
        with:
          name: ChatFixer-PHAR
          path: /tmp/build/ChatFixer.phar
          if-no-files-found: error

      - name: Create Release
        if: startsWith(github.ref, 'refs/tags/v')
        uses: softprops/action-gh-release@v2
        with:
          files: /tmp/build/ChatFixer.phar
          generate_release_notes: true
          draft: false
          prerelease: false
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
