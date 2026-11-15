#!/usr/bin/env node
/**
 * 以项目根目录（而不是 uni CLI 默认的 src/）作为源码目录启动 H5 编译。
 *
 * 本项目沿用 HBuilderX 的目录结构，manifest.json / pages.json 都在根目录，
 * 而 UNI_INPUT_DIR 必须在 CLI 初始化前设置，所以用这个脚本包一层。
 */
import path from 'node:path';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
const building = args[0] === 'build';

process.env.UNI_INPUT_DIR = process.env.UNI_INPUT_DIR || root;
process.env.UNI_OUTPUT_DIR = process.env.UNI_OUTPUT_DIR ||
  path.join(root, 'unpackage', 'dist', building ? 'build' : 'dev', 'h5');

const bin = path.join(root, 'node_modules', '@dcloudio', 'vite-plugin-uni', 'bin', 'uni.js');

const child = spawn(process.execPath, [bin, ...args], {
  cwd: root,
  env: process.env,
  stdio: 'inherit',
});

child.on('exit', (code, signal) => {
  if (signal) {
    process.kill(process.pid, signal);
    return;
  }

  process.exit(code ?? 1);
});
