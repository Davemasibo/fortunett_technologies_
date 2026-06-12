package site.fortunetttech.admin.ui.routers;

import dagger.internal.DaggerGenerated;
import dagger.internal.Factory;
import dagger.internal.QualifierMetadata;
import dagger.internal.ScopeMetadata;
import javax.annotation.processing.Generated;
import javax.inject.Provider;
import site.fortunetttech.admin.data.repository.RouterRepository;

@ScopeMetadata
@QualifierMetadata
@DaggerGenerated
@Generated(
    value = "dagger.internal.codegen.ComponentProcessor",
    comments = "https://dagger.dev"
)
@SuppressWarnings({
    "unchecked",
    "rawtypes",
    "KotlinInternal",
    "KotlinInternalInJava",
    "cast"
})
public final class RoutersViewModel_Factory implements Factory<RoutersViewModel> {
  private final Provider<RouterRepository> repoProvider;

  public RoutersViewModel_Factory(Provider<RouterRepository> repoProvider) {
    this.repoProvider = repoProvider;
  }

  @Override
  public RoutersViewModel get() {
    return newInstance(repoProvider.get());
  }

  public static RoutersViewModel_Factory create(Provider<RouterRepository> repoProvider) {
    return new RoutersViewModel_Factory(repoProvider);
  }

  public static RoutersViewModel newInstance(RouterRepository repo) {
    return new RoutersViewModel(repo);
  }
}
