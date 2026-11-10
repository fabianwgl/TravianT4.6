-- Develops the test player's village (kid 11314, uid 4, Romans) so pages render a realistic mid-game village.
UPDATE fdata SET f1=8,f2=8,f3=8,f4=8,f5=8,f6=8,f7=8,f8=8,f9=8,f10=8,f11=8,f12=8,f13=8,f14=8,f15=8,f16=8,f17=8,f18=8,
 f19=10,f19t=19, f20=15,f20t=10, f21=15,f21t=11, f22=10,f22t=17, f23=10,f23t=22, f24=10,f24t=13,
 f25=5,f25t=25, f26=15,f26t=15, f27=10,f27t=20, f28=5,f28t=23, f29=10,f29t=24,
 f39=10,f39t=16, f40=10,f40t=31 WHERE kid=11314;
UPDATE vdata SET wood=700000,clay=700000,iron=700000,crop=700000,maxstore=800000,maxcrop=800000,pop=400 WHERE kid=11314;
UPDATE units SET u1=5000,u2=3000,u3=3000,u5=1500,u6=1500,u7=100,u8=50 WHERE kid=11314;
