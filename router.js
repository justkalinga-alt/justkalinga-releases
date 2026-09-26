(()=>{
  if(window.__jkshDriveRouterV101)return; window.__jkshDriveRouterV101=true;
  const cfg=window.JKSHDriveStorage||{}, prefix=String(cfg.prefix||'jkdrv1.'), nativeFetch=window.fetch.bind(window), sessions=new Map();
  const good=data=>new Response(JSON.stringify({success:true,data}),{status:200,headers:{'Content-Type':'application/json'}});
  const bad=(message,status=500)=>new Response(JSON.stringify({success:false,data:{message}}),{status,headers:{'Content-Type':'application/json'}});
  const hints=()=>document.querySelectorAll('body *').forEach(el=>{if(el.children.length)return;let t=el.textContent||'';if(t.includes('>50 MB · WordPress Media'))el.textContent=t.replace('>50 MB · WordPress Media','>50 MB · Google Drive');if(t.includes('>50 MB • WordPress Media'))el.textContent=t.replace('>50 MB • WordPress Media','>50 MB • Google Drive');});
  new MutationObserver(()=>{hints();const s=document.getElementById('jkssb-status');if(s&&window.__jkshDriveActive&&s.textContent.includes('Supabase'))s.textContent=s.textContent.replace('Supabase','Google Drive');const r=document.getElementById('jkssb-ready');if(r&&window.__jkshDriveActive)r.querySelectorAll('*').forEach(e=>{if(!e.children.length&&e.textContent.includes('Supabase'))e.textContent=e.textContent.replace('Supabase','Google Drive');});}).observe(document.documentElement,{subtree:true,childList:true,characterData:true}); hints();
  window.fetch=async(input,init)=>{
    const body=init&&init.body;
    if(body instanceof FormData){
      const action=String(body.get('action')||''), uploadId=String(body.get('upload_id')||'');
      if(action==='jkssb_ui_begin'){
        const total=Number(body.get('bytes')||0), r=await nativeFetch(input,init);
        try{const j=await r.clone().json();if(j?.success&&String(j.data?.upload_id||'').startsWith(prefix)&&j.data?.drive_resumable_url){sessions.set(String(j.data.upload_id),{url:String(j.data.drive_resumable_url),total,file:null});window.__jkshDriveActive=true;}}catch(e){}
        return r;
      }
      if(action==='jkssb_ui_chunk'&&uploadId.startsWith(prefix)){
        const s=sessions.get(uploadId), blob=body.get('chunk'), start=Number(body.get('offset')||0);if(!s||!s.url)return bad('Drive upload session missing.',409);if(!(blob instanceof Blob)||!s.total)return bad('Drive upload chunk metadata is invalid.',400);const end=start+blob.size-1;
        let r;try{r=await nativeFetch(s.url,{method:'PUT',headers:{'Content-Range':`bytes ${start}-${end}/${s.total}`,'Content-Type':blob.type||'application/octet-stream'},body:blob});}catch(e){return bad('Google Drive upload network error: '+(e?.message||'request failed'),502);}
        if(r.status===308)return good({next_offset:end+1,storage:'google_drive',provider:'google_drive'});if(r.ok){let meta={};try{meta=await r.clone().json();}catch(e){}s.file=meta;sessions.set(uploadId,s);return good({next_offset:end+1,storage:'google_drive',provider:'google_drive',drive:meta});}let msg='Google Drive upload failed (HTTP '+r.status+').';try{const e=await r.json();if(e?.error?.message)msg=e.error.message;}catch(e){}return bad(msg,r.status||502);
      }
      if(action==='jkssb_ui_finalize'&&uploadId.startsWith(prefix)){
        const s=sessions.get(uploadId);if(!s?.file?.id)return bad('Drive upload finished without a file receipt.',409);body.set('action','jksh_drive_register');body.set('drive_file_id',String(s.file.id));const r=await nativeFetch(String(cfg.ajax||window.ajaxurl),Object.assign({},init,{body}));try{const j=await r.clone().json();if(j?.success){sessions.delete(uploadId);window.__jkshDriveActive=true;}}catch(e){}return r;
      }
    }
    return nativeFetch(input,init);
  };
})();