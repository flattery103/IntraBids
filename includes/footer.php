</main>
<footer class="footer">
    <span>IntraBid internal auction system &mdash; v<?= h(intrabid_version()) ?></span>
</footer>
<script>
(function(){
  function tick(){
    document.querySelectorAll('[data-countdown]').forEach(function(el){
      var end = new Date(el.getAttribute('data-countdown')).getTime();
      var now = Date.now();
      var diff = Math.max(0, Math.floor((end-now)/1000));
      var d = Math.floor(diff/86400); diff %= 86400;
      var h = Math.floor(diff/3600); diff %= 3600;
      var m = Math.floor(diff/60); var s = diff % 60;
      el.textContent = (d>0 ? d+'d ' : '') + h+'h '+m+'m '+s+'s';
    });
  }
  tick(); setInterval(tick, 1000);
})();
</script>
</body>
</html>
