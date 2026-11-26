// Task-owned inputs copied from the current module's real html-import.spec.ts.
// No application route or product source changes.
export const cases = [
  {id:'safe-structure',title:'有效：结构/标记',expected:'allow',html:'<h2>Title</h2><p>Hello <strong>bold</strong> <em>italic</em><br><u>underlined</u></p><blockquote><p>Quote</p></blockquote><ul><li><p>Item</p></li></ul><pre><code>a  b\nc</code></pre><hr>'},
  {id:'safe-link-list',title:'有效：链接和有序列表',expected:'allow',html:'<ol start="3"><li><p><a href="https://example.com/a?x=1&amp;y=2">Link</a></p></li></ol>'},
  {id:'reject-img',title:'拒绝：外部图片',expected:'reject',html:'<img src="https://example.com/image.png">'},
  {id:'reject-video',title:'拒绝：外部视频',expected:'reject',html:'<video src="https://example.com/video.mp4"></video>'},
  {id:'reject-iframe',title:'拒绝：iframe',expected:'reject',html:'<iframe src="https://example.com"></iframe>'},
  {id:'reject-script',title:'拒绝：script',expected:'reject',html:'<script>alert(1)</script>'},
  {id:'reject-onclick',title:'拒绝：onclick',expected:'reject',html:'<p onclick="alert(1)">Text</p>'},
  {id:'reject-onload',title:'拒绝：html/body onload',expected:'reject',html:'<html><body onload="alert(1)"><p>Text</p></body></html>'},
  {id:'reject-style',title:'拒绝：内联样式',expected:'reject',html:'<p style="text-align:center">Text</p>'},
  {id:'reject-color',title:'拒绝：颜色样式',expected:'reject',html:'<span style="color:red">Text</span>'},
  {id:'reject-custom',title:'拒绝：自定义元素',expected:'reject',html:'<custom-element>Text</custom-element>'},
  {id:'reject-js-link',title:'拒绝：javascript 链接',expected:'reject',html:'<a href="javascript:alert(1)">Link</a>'},
  {id:'reject-malformed-link',title:'拒绝：无效协议链接',expected:'reject',html:'<p><a href="https:example.com">Link</a></p>'},
  {id:'reject-file-link',title:'拒绝：私有文件路径',expected:'reject',html:'<p><a href="/storage/delivery/example">Link</a></p>'},
  {id:'reject-title',title:'拒绝：额外链接属性',expected:'reject',html:'<p><a href="https://example.com" title="lost">Link</a></p>'},
  {id:'reject-colspan',title:'拒绝：表格合并',expected:'reject',html:'<table><tr><td colspan="2"><p>Cell</p></td></tr></table>'},
  {id:'reject-empty-mark',title:'拒绝：无内容标记',expected:'reject',html:'<p><strong></strong>Empty mark</p>'},
  {id:'reject-plain-text',title:'拒绝：无段落文本',expected:'reject',html:'unwrapped text'},
  {id:'reject-empty',title:'拒绝：空输入',expected:'reject',html:''},
  {id:'safe-whitespace',title:'有效：空白和实体',expected:'allow',html:'<p> a  b &amp; &lt;x&gt; </p>'},
  {id:'safe-merged-strike',title:'有效：等价连续标记',expected:'allow',html:'<p><strike>S</strike><del>D</del></p>'},
  {id:'safe-paragraph-links',title:'有效：段落及不同链接',expected:'allow',html:'<p><b>A</b></p><p><strong>B</strong><a href="https://one.invalid">C</a><a href="https://two.invalid">D</a></p>'}
] as const;
