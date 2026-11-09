/**
 * Dynamic Metafield — editor entry.
 */
import './components/binding-panel';
import { registerScfBindingsSource } from './bindings-source';
import { registerLoopBlock } from './loop-block';
import './editor.css';

registerScfBindingsSource();
registerLoopBlock();
